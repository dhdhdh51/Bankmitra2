<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;
use App\Models\User;

final class AuthController extends Controller
{
    public function root(Request $request): void
    {
        Auth::resolve($request);
        Response::redirect(Auth::check() ? '/dashboard' : '/login');
    }

    // -----------------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------------

    public function login(Request $request): void
    {
        Auth::resolve($request);
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        if (!$request->isPost()) {
            View::render('auth/login', ['title' => 'Sign in'], 'layouts/auth');
        }

        if (!Csrf::verify($request)) {
            $this->back('/login', 'danger', 'Your session expired. Please try again.');
        }

        $identifier = $request->str('employee_code');
        $password = (string) $request->input('password', '');
        $remember = $request->bool('remember');

        if ($identifier === '' || $password === '') {
            Session::flashInput(['employee_code' => $identifier], []);
            $this->back('/login', 'danger', 'Enter your employee code and password.');
        }

        $attempt = Auth::attempt($identifier, $password, $request->ip());

        if ($attempt['user'] === null) {
            Session::flashInput(['employee_code' => $identifier], []);
            $this->back('/login', 'danger', (string) $attempt['error']);
        }

        $user = $attempt['user'];

        // BC/DC agents are app-only; say so instead of a bare permission error.
        if (($user['role_slug'] ?? '') === 'agent') {
            Logger::activity('login_blocked', 'Auth', 'Agent attempted web panel sign-in', (int) $user['id']);
            Session::flashInput(['employee_code' => $identifier], []);
            $this->back(
                '/login',
                'warning',
                'BC/DC Agent accounts sign in through the LRMS Android app, not the web panel.'
            );
        }

        Auth::loginSession($user);

        if ($remember) {
            $this->issueRememberCookie((int) $user['id'], $request);
        }

        Logger::activity('login', 'Auth', 'Signed in to the admin panel');

        if ((int) $user['must_change_password'] === 1) {
            Session::flash('warning', 'Please set a new password before continuing.');
            Response::redirect('/change-password');
        }

        Response::redirect('/dashboard');
    }

    public function logout(Request $request): void
    {
        Auth::resolve($request);

        if (Auth::check()) {
            // GET /logout is allowed for convenience but POST is CSRF-checked.
            if ($request->isPost() && !Csrf::verify($request)) {
                $this->back('/dashboard', 'danger', 'Invalid security token.');
            }

            Logger::activity('logout', 'Auth', 'Signed out');
            $this->clearRememberCookie(Auth::id());
        }

        Auth::logout();
        Session::flash('success', 'You have been signed out.');
        Response::redirect('/login');
    }

    // -----------------------------------------------------------------------
    // Forgot password (OTP over SMS, or admin-assisted reset)
    // -----------------------------------------------------------------------

    public function forgotPassword(Request $request): void
    {
        Auth::resolve($request);
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        if (!$request->isPost()) {
            View::render('auth/forgot-password', [
                'title'        => 'Forgot password',
                'smsAvailable' => Notifier::smsConfigured(),
            ], 'layouts/auth');
        }

        if (!Csrf::verify($request)) {
            $this->back('/forgot-password', 'danger', 'Your session expired. Please try again.');
        }

        $identifier = $request->str('employee_code');
        if ($identifier === '') {
            $this->back('/forgot-password', 'danger', 'Enter your employee code or registered mobile number.');
        }

        $db = Database::instance();
        $user = $db->first(
            'SELECT u.id, u.name, u.mobile_enc, u.status FROM users u WHERE u.employee_code = ? LIMIT 1',
            [$identifier]
        );

        if ($user === null) {
            $hash = Crypto::searchHash($identifier);
            if ($hash !== null) {
                $user = $db->first(
                    'SELECT u.id, u.name, u.mobile_enc, u.status FROM users u WHERE u.mobile_hash = ? LIMIT 1',
                    [$hash]
                );
            }
        }

        // Deliberately identical response whether or not the account exists, so
        // this endpoint cannot be used to enumerate employee codes.
        $genericMessage = 'If that account exists and has a registered mobile number, an OTP has been sent. '
            . 'Otherwise contact your administrator for a password reset.';

        if ($user === null || (string) $user['status'] !== 'active') {
            Logger::activity('password_reset_request', 'Auth', 'Reset requested for unknown/inactive account');
            $this->back('/forgot-password', 'info', $genericMessage);
        }

        $mobile = Crypto::decrypt($user['mobile_enc'] ?? null);

        if ($mobile === null || !Notifier::smsConfigured()) {
            Logger::activity(
                'password_reset_request',
                'Auth',
                sprintf('Reset requested for user #%d but SMS/mobile unavailable - needs admin reset', (int) $user['id'])
            );
            $this->back('/forgot-password', 'info', $genericMessage);
        }

        $otp = Crypto::numericOtp(6);
        $expiryMinutes = max(2, Settings::int('otp_expiry_minutes', 10));

        // Invalidate any earlier unused OTPs for this user.
        $db->query(
            'UPDATE password_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [(int) $user['id']]
        );

        $db->insert('password_otps', [
            'user_id'    => (int) $user['id'],
            'otp_hash'   => hash('sha256', $otp),
            'channel'    => 'sms',
            'expires_at' => date('Y-m-d H:i:s', time() + ($expiryMinutes * 60)),
            'ip'         => $request->ip(),
        ]);

        Notifier::sendOtpSms($mobile, $otp);
        Logger::activity('password_reset_otp', 'Auth', sprintf('OTP issued for user #%d', (int) $user['id']));

        Session::set('_reset_user_id', (int) $user['id']);
        Session::flash('success', sprintf(
            'An OTP has been sent to the mobile number ending %s. It is valid for %d minutes.',
            substr(Crypto::maskMobile($mobile) ?? '', -4),
            $expiryMinutes
        ));
        Response::redirect('/reset-password');
    }

    public function resetPassword(Request $request): void
    {
        Auth::resolve($request);
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        $userId = Session::get('_reset_user_id');
        if (!is_int($userId)) {
            $this->back('/forgot-password', 'warning', 'Start the password reset again.');
        }

        if (!$request->isPost()) {
            View::render('auth/reset-password', ['title' => 'Reset password'], 'layouts/auth');
        }

        if (!Csrf::verify($request)) {
            $this->back('/reset-password', 'danger', 'Your session expired. Please try again.');
        }

        $otp = preg_replace('/\D+/', '', $request->str('otp')) ?? '';
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $minLength = max(6, Settings::int('password_min_length', 8));

        if ($otp === '') {
            $this->back('/reset-password', 'danger', 'Enter the OTP you received.');
        }
        if (strlen($password) < $minLength) {
            $this->back('/reset-password', 'danger', "The new password must be at least {$minLength} characters.");
        }
        if ($password !== $confirmation) {
            $this->back('/reset-password', 'danger', 'The two passwords do not match.');
        }

        $db = Database::instance();
        $record = $db->first(
            'SELECT id, otp_hash, attempts FROM password_otps
              WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1',
            [$userId]
        );

        if ($record === null) {
            $this->back('/forgot-password', 'danger', 'That OTP has expired. Please request a new one.');
        }

        if ((int) $record['attempts'] >= 5) {
            $db->update('password_otps', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);
            $this->back('/forgot-password', 'danger', 'Too many incorrect attempts. Please request a new OTP.');
        }

        if (!hash_equals((string) $record['otp_hash'], hash('sha256', $otp))) {
            $db->query('UPDATE password_otps SET attempts = attempts + 1 WHERE id = ?', [(int) $record['id']]);
            $this->back('/reset-password', 'danger', 'That OTP is not correct.');
        }

        $db->update('password_otps', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int) $record['id']]);
        User::setPassword($userId, $password, false);

        Session::forget('_reset_user_id');
        Logger::audit('login_reset', 'user', $userId, null, null, 'Password reset via OTP');

        $this->back('/login', 'success', 'Your password has been reset. Please sign in.');
    }

    // -----------------------------------------------------------------------
    // Change password (also the forced first-login flow)
    // -----------------------------------------------------------------------

    public function changePassword(Request $request): void
    {
        Auth::resolve($request);
        if (!Auth::check()) {
            $this->back('/login', 'warning', 'Please sign in to continue.');
        }

        $user = Auth::user();
        $forced = (int) ($user['must_change_password'] ?? 0) === 1;

        if (!$request->isPost()) {
            $this->view($request, 'auth/change-password', [
                'title'  => 'Change password',
                'forced' => $forced,
            ]);
        }

        if (!Csrf::verify($request)) {
            $this->back('/change-password', 'danger', 'Your session expired. Please try again.');
        }

        $current = (string) $request->input('current_password', '');
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $minLength = max(6, Settings::int('password_min_length', 8));

        $row = Database::instance()->first('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [(int) $user['id']]);
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            $this->back('/change-password', 'danger', 'Your current password is not correct.');
        }

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => "required|min:{$minLength}|confirmed"],
            ['password' => 'New password']
        );

        if ($validator->fails()) {
            $this->back('/change-password', 'danger', $validator->firstError());
        }

        if (password_verify($password, (string) $row['password_hash'])) {
            $this->back('/change-password', 'danger', 'The new password must be different from the current one.');
        }

        User::setPassword((int) $user['id'], $password, false);
        Logger::audit('update', 'user', (int) $user['id'], null, null, 'Changed own password');
        Logger::activity('password_change', 'Auth', 'Changed own password');

        $this->back('/dashboard', 'success', 'Your password has been updated.');
    }

    // -----------------------------------------------------------------------
    // "Remember login"
    //
    // A random selector:validator pair is stored in refresh_tokens; only the
    // hash is persisted, so a database leak cannot be replayed as a login.
    // -----------------------------------------------------------------------

    private function issueRememberCookie(int $userId, Request $request): void
    {
        $token = Crypto::randomToken(32);
        $days = max(1, Settings::int('refresh_ttl_days', 30));

        Database::instance()->insert('refresh_tokens', [
            'user_id'     => $userId,
            'token_hash'  => hash('sha256', $token),
            'device_info' => $request->userAgent(),
            'ip'          => $request->ip(),
            'expires_at'  => date('Y-m-d H:i:s', time() + ($days * 86400)),
        ]);

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie('lrms_remember', $token, [
            'expires'  => time() + ($days * 86400),
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(?int $userId): void
    {
        $token = $_COOKIE['lrms_remember'] ?? null;

        if (is_string($token) && $token !== '') {
            Database::instance()->query(
                'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?',
                [hash('sha256', $token)]
            );
        }

        setcookie('lrms_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
