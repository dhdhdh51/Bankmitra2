<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TrackingService;

/**
 * Location notice, consent, and the location trail itself.
 *
 * The ordering here is the whole design: the app must fetch the notice and post an
 * acknowledgement before any location endpoint will accept a single point. There is
 * no way to start collecting first and ask afterwards, because the service throws
 * rather than returning a soft failure.
 */
final class TrackingController extends Controller
{
    /**
     * The notice, plus whether this agent has already acknowledged this version.
     *
     * The app calls this at launch. A version the agent has not acknowledged means
     * the consent screen is shown again, which is what makes changing the notice
     * text a real re-consent rather than a silent edit.
     */
    public function notice(Request $request): void
    {
        $user = $this->auth($request);
        $notice = TrackingService::notice();

        Response::success([
            'version'          => $notice['version'],
            'english'          => $notice['english'],
            'hindi'            => $notice['hindi'],
            'retention_days'   => TrackingService::retentionDays(),
            'acknowledged'     => TrackingService::hasConsented((int) $user['id']),
            // The app blocks a duty session until this is true, so it is stated
            // explicitly rather than left for the client to infer.
            'tracking_allowed' => TrackingService::hasConsented((int) $user['id']),
        ]);
    }

    /** Records this agent's acknowledgement of the current notice version. */
    public function consent(Request $request): void
    {
        $user = $this->auth($request);

        $version = (string) $request->input('notice_version', '');
        if ($version !== TrackingService::NOTICE_VERSION) {
            // A stale version means the app is showing older text than the server
            // would now collect under. Accepting it would record consent to
            // something the agent was never shown.
            Response::error(
                'This notice has been updated. Please reopen the app and read the current notice.',
                409
            );
        }

        TrackingService::recordConsent(
            (int) $user['id'],
            $request->input('device_info') === null ? null : (string) $request->input('device_info'),
            $request->ip()
        );

        Response::success(['acknowledged' => true, 'version' => $version], 'Thank you. Recorded.');
    }

    /** Stops collection for this agent. */
    public function withdraw(Request $request): void
    {
        $user = $this->auth($request);
        TrackingService::withdrawConsent((int) $user['id']);

        Response::success(
            ['acknowledged' => false],
            'Location recording has been stopped. Please speak to your supervisor.'
        );
    }

    /**
     * Accepts a batch of location fixes.
     *
     * Batched because a phone in a village with no signal has to queue points and
     * send them when it can; one request per fix would lose most of them. Each point
     * is validated on its own so one bad reading cannot reject the whole batch.
     */
    public function location(Request $request): void
    {
        $user = $this->auth($request);
        $agentId = (int) $user['id'];

        if (!TrackingService::hasConsented($agentId)) {
            // 412 rather than 403: nothing is wrong with the request or the token,
            // the precondition simply has not been met yet. The app reads this as
            // "show the consent screen", not as "you are not allowed".
            Response::json(
                false,
                ['consent_required' => true, 'notice_version' => TrackingService::NOTICE_VERSION],
                'The location notice has not been acknowledged yet.',
                412
            );
        }

        $points = $request->input('points');
        if (!is_array($points) || $points === []) {
            Response::error('Send a non-empty points array.', 422);
        }

        // A device that has been offline for a week must not be able to post its
        // whole history in one request.
        if (count($points) > 200) {
            $points = array_slice($points, -200);
        }

        $stored = 0;
        $dropped = 0;
        $rejected = [];

        foreach ($points as $index => $point) {
            if (!is_array($point) || !isset($point['latitude'], $point['longitude'])) {
                $rejected[] = ['index' => $index, 'reason' => 'latitude and longitude are required'];
                continue;
            }

            try {
                $accepted = TrackingService::record($agentId, [
                    'latitude'   => (float) $point['latitude'],
                    'longitude'  => (float) $point['longitude'],
                    'accuracy_m' => isset($point['accuracy_m']) ? (int) $point['accuracy_m'] : null,
                    'logged_at'  => isset($point['logged_at']) ? (string) $point['logged_at'] : null,
                    'on_duty'    => (bool) ($point['on_duty'] ?? true),
                ]);
                $accepted ? $stored++ : $dropped++;
            } catch (\Throwable $e) {
                $rejected[] = ['index' => $index, 'reason' => $e->getMessage()];
            }
        }

        Response::success([
            'stored'   => $stored,
            // Dropped means "accepted but too close to the previous fix" - the app
            // must treat these as delivered and clear them from its queue, or it
            // will retry them forever.
            'dropped'  => $dropped,
            'rejected' => $rejected,
        ]);
    }

    /**
     * One agent's trail for one day.
     *
     * An agent may only read their own. A supervisor reading somebody else's is
     * allowed within their branch scope and is audited by the service.
     */
    public function trail(Request $request): void
    {
        $user = $this->auth($request);
        $viewerId = (int) $user['id'];
        $agentId = $request->paramInt('id');
        $date = (string) $request->input('date', date('Y-m-d'));

        if (strtotime($date) === false) {
            Response::error('date must be YYYY-MM-DD.', 422);
        }

        if ($agentId !== $viewerId) {
            if (Auth::isAgent()) {
                Response::forbidden('You can only view your own location history.');
            }

            $agent = \App\Models\User::find($agentId);
            if ($agent === null) {
                Response::notFound('That user could not be found.');
            }
            Auth::assertBranchAccess($agent['branch_id'] === null ? null : (int) $agent['branch_id']);
        }

        Response::success([
            'agent_id' => $agentId,
            'date'     => $date,
            'points'   => TrackingService::trailFor($agentId, $date, $viewerId),
        ]);
    }
}
