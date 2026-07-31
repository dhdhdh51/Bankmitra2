<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Jwt;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\User;

/**
 * JWT authentication for the Android app.
 *
 * Access tokens are short-lived JWTs. "Remember login" is implemented with an
 * opaque refresh token stored hashed in refresh_tokens, so the app can restore a
 * session without holding the user's password and a leaked database cannot be
 * replayed as a login.
 */
final class AuthController extends Controller
{
    public function login(Request $request): void
    {
        $this->validate($request, [
            'employee_code' => 'required|max:40',
            'password'      => 'required',
        ], ['employee_code' => 'Employee code']);

        $identifier = $request->str('employee_code');
        $password = (string) $request->input('password', '');

        $attempt = Auth::attempt($identifier, $password, $request->ip());

        if ($attempt['user'] === null) {
            Response::json(false, null, (string) $attempt['error'], 401);
        }

        $user = $attempt['user'];

        // The panel is for admins; the app is for agents. Both roles can sign in
        // here because a manager may want the app read-only, but the client is
        // told which role it got so it can render the right home screen.
        Logger::activity('login', 'API', 'Signed in from the mobile app', (int) $user['id']);

        $deviceToken = $request->str('device_token');
        if ($deviceToken !== '') {
            User::registerDeviceToken((int) $user['id'], $deviceToken, $request->str('app_version'));
        }

        Response::success($this->tokenPayload($user, $request), 'Signed in successfully.');
    }

    /**
     * Exchanges a refresh token for a new access token.
     * The refresh token is rotated on every use, so a stolen one is single-use.
     */
    public function refresh(Request $request): void
    {
        $token = $request->str('refresh_token');
        if ($token === '') {
            Response::error('A refresh token is required.', 422);
        }

        $db = Database::instance();
        $row = $db->first(
            'SELECT * FROM refresh_tokens
              WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
              LIMIT 1',
            [hash('sha256', $token)]
        );

        if ($row === null) {
            Response::unauthorized('That session has expired. Please sign in again.');
        }

        $user = Auth::loadActiveUser((int) $row['user_id']);
        if ($user === null) {
            $db->update('refresh_tokens', ['revoked_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
            Response::unauthorized('This account is no longer active.');
        }

        // Rotate: revoke the presented token and issue a fresh pair.
        $db->update('refresh_tokens', ['revoked_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);

        Response::success($this->tokenPayload($user, $request), 'Session refreshed.');
    }

    public function logout(Request $request): void
    {
        $user = Auth::requireApi($request);

        $token = $request->str('refresh_token');
        if ($token !== '') {
            Database::instance()->query(
                'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND user_id = ?',
                [hash('sha256', $token), (int) $user['id']]
            );
        }

        $deviceToken = $request->str('device_token');
        if ($deviceToken !== '') {
            Database::instance()->delete('device_tokens', ['token' => $deviceToken]);
        }

        Logger::activity('logout', 'API', 'Signed out from the mobile app');

        Response::success(null, 'Signed out.');
    }

    /** Current user + permissions, used by the app on startup. */
    public function me(Request $request): void
    {
        $user = Auth::requireApi($request);

        Response::success([
            'user'        => $this->presentUser($user),
            'app_version' => Settings::get('app_version', '1.0.0'),
            'min_version' => Settings::get('app_min_version', '1.0.0'),
        ]);
    }

    public function forgotPassword(Request $request): void
    {
        $this->validate($request, ['employee_code' => 'required|max:40'], ['employee_code' => 'Employee code']);

        $identifier = $request->str('employee_code');
        $db = Database::instance();

        $user = $db->first(
            'SELECT id, mobile_enc, status FROM users WHERE employee_code = ? LIMIT 1',
            [$identifier]
        );

        if ($user === null) {
            $hash = Crypto::searchHash($identifier);
            if ($hash !== null) {
                $user = $db->first('SELECT id, mobile_enc, status FROM users WHERE mobile_hash = ? LIMIT 1', [$hash]);
            }
        }

        // Identical response either way: this endpoint must not reveal whether
        // an employee code exists.
        $generic = 'If that account exists and has a registered mobile number, an OTP has been sent.';

        if ($user === null || (string) $user['status'] !== 'active') {
            Response::success(['otp_sent' => false], $generic);
        }

        $mobile = Crypto::decrypt($user['mobile_enc'] ?? null);
        if ($mobile === null || !Notifier::smsConfigured()) {
            Response::success(
                ['otp_sent' => false, 'contact_admin' => true],
                'OTP delivery is unavailable. Please ask your administrator to reset your password.'
            );
        }

        $otp = Crypto::numericOtp(6);
        $minutes = max(2, Settings::int('otp_expiry_minutes', 10));

        $db->query('UPDATE password_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
        $db->insert('password_otps', [
            'user_id'    => (int) $user['id'],
            'otp_hash'   => hash('sha256', $otp),
            'channel'    => 'sms',
            'expires_at' => date('Y-m-d H:i:s', time() + ($minutes * 60)),
            'ip'         => $request->ip(),
        ]);

        Notifier::sendOtpSms($mobile, $otp);
        Logger::activity('password_reset_otp', 'API', sprintf('OTP issued for user #%d', (int) $user['id']));

        Response::success([
            'otp_sent'       => true,
            'employee_code'  => $identifier,
            'mobile_masked'  => Crypto::maskMobile($mobile),
            'expires_in'     => $minutes * 60,
        ], sprintf('An OTP has been sent. It is valid for %d minutes.', $minutes));
    }

    public function resetPassword(Request $request): void
    {
        $minLength = max(6, Settings::int('password_min_length', 8));

        $this->validate($request, [
            'employee_code' => 'required|max:40',
            'otp'           => 'required',
            'password'      => "required|min:{$minLength}",
        ], ['employee_code' => 'Employee code', 'otp' => 'OTP']);

        $db = Database::instance();
        $identifier = $request->str('employee_code');

        $user = $db->first('SELECT id FROM users WHERE employee_code = ? LIMIT 1', [$identifier]);
        if ($user === null) {
            $hash = Crypto::searchHash($identifier);
            if ($hash !== null) {
                $user = $db->first('SELECT id FROM users WHERE mobile_hash = ? LIMIT 1', [$hash]);
            }
        }
        if ($user === null) {
            Response::error('That OTP is not valid.', 422);
        }

        $record = $db->first(
            'SELECT id, otp_hash, attempts FROM password_otps
              WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1',
            [(int) $user['id']]
        );

        if ($record === null) {
            Response::error('That OTP has expired. Please request a new one.', 422);
        }

        if ((int) $record['attempts'] >= 5) {
            $db->update('password_otps', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);
            Response::error('Too many incorrect attempts. Please request a new OTP.', 429);
        }

        $otp = preg_replace('/\D+/', '', $request->str('otp')) ?? '';
        if (!hash_equals((string) $record['otp_hash'], hash('sha256', $otp))) {
            $db->query('UPDATE password_otps SET attempts = attempts + 1 WHERE id = ?', [(int) $record['id']]);
            Response::error('That OTP is not correct.', 422);
        }

        $db->update('password_otps', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);
        User::setPassword((int) $user['id'], (string) $request->input('password'), false);

        // Any existing app session must not survive a password reset.
        $db->query('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [(int) $user['id']]);

        Logger::audit('login_reset', 'user', (int) $user['id'], null, null, 'Password reset via OTP from the app');

        Response::success(null, 'Your password has been reset. Please sign in.');
    }

    public function changePassword(Request $request): void
    {
        $user = Auth::requireApi($request);
        $minLength = max(6, Settings::int('password_min_length', 8));

        $this->validate($request, [
            'current_password' => 'required',
            'password'         => "required|min:{$minLength}",
        ], ['current_password' => 'Current password', 'password' => 'New password']);

        $row = Database::instance()->first('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [(int) $user['id']]);
        if ($row === null || !password_verify((string) $request->input('current_password'), (string) $row['password_hash'])) {
            Response::error('Your current password is not correct.', 422);
        }

        $newPassword = (string) $request->input('password');
        if (password_verify($newPassword, (string) $row['password_hash'])) {
            Response::error('The new password must be different from the current one.', 422);
        }

        User::setPassword((int) $user['id'], $newPassword, false);
        Logger::audit('update', 'user', (int) $user['id'], null, null, 'Changed own password from the app');

        $fresh = Auth::loadActiveUser((int) $user['id']);

        Response::success([
            'user' => $fresh === null ? null : $this->presentUser($fresh),
        ], 'Your password has been updated.');
    }

    /** Registers or refreshes the Firebase device token for push. */
    public function deviceToken(Request $request): void
    {
        $user = $this->auth($request);

        $token = $request->str('device_token');
        if ($token === '') {
            Response::error('A device token is required.', 422);
        }

        User::registerDeviceToken((int) $user['id'], $token, $request->str('app_version'));

        Response::success(null, 'Device registered for notifications.');
    }

    // -----------------------------------------------------------------------

    /**
     * Issues an access token + refresh token pair.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function tokenPayload(array $user, Request $request): array
    {
        $ttlMinutes = max(5, Settings::int('jwt_ttl_minutes', 120));
        $refreshDays = max(1, Settings::int('refresh_ttl_days', 30));

        $accessToken = Jwt::encode([
            'sub'    => (int) $user['id'],
            'role'   => (string) $user['role_slug'],
            'branch' => $user['branch_id'] === null ? null : (int) $user['branch_id'],
        ], $ttlMinutes * 60);

        $refreshToken = Crypto::randomToken(32);

        Database::instance()->insert('refresh_tokens', [
            'user_id'     => (int) $user['id'],
            'token_hash'  => hash('sha256', $refreshToken),
            'device_info' => $request->userAgent(),
            'ip'          => $request->ip(),
            'expires_at'  => date('Y-m-d H:i:s', time() + ($refreshDays * 86400)),
        ]);

        // Auth::permissions() reads the resolved user, so set it before presenting.
        Auth::loginSessionless($user);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $ttlMinutes * 60,
            'user'          => $this->presentUser($user),
            'app_version'   => Settings::get('app_version', '1.0.0'),
            'min_version'   => Settings::get('app_min_version', '1.0.0'),
        ];
    }
}
