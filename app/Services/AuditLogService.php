<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'remember',
        '_token',
        'current_password',
        'new_password',
        'authorization',
    ];

    public function record(
        string $description,
        array $context = [],
        ?Model $subject = null,
        ?User $causer = null,
    ): void {
        if (! config('activitylog.enabled', true)) {
            return;
        }

        $causer ??= auth()->user();

        $activity = activity('audit')
            ->withProperties($context);

        if ($causer instanceof User) {
            $activity->causedBy($causer);
        }

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity->log($description);
    }

    public function recordHttpRequest(Request $request, int $statusCode, string $channel): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->shouldSkipHttpPath($request)) {
            return;
        }

        $route = $request->route();
        $routeName = $route?->getName() ?? $request->path();

        $this->record(
            "طلب {$request->method()} — {$routeName} ({$statusCode})",
            [
                'channel' => $channel,
                'method' => $request->method(),
                'route' => $routeName,
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'organization_id' => $user->organization_id,
                'status_code' => $statusCode,
                'payload_summary' => $this->sanitize($request->except(self::SENSITIVE_KEYS)),
            ],
            causer: $user,
        );
    }

    public function recordAuth(string $description, array $context = [], ?User $causer = null): void
    {
        $this->record($description, array_merge(['channel' => 'auth'], $context), causer: $causer);
    }

    public function recordWorkflow(string $description, Model $subject, array $context = [], ?User $causer = null): void
    {
        $this->record(
            $description,
            array_merge(['channel' => 'workflow'], $context),
            $subject,
            $causer,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $out[$key] = substr($value, 0, 500).'…';

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function shouldSkipHttpPath(Request $request): bool
    {
        if ($request->is('livewire/*', 'admin/livewire/*', 'vendor/*', 'css/*', 'js/*', 'build/*')) {
            return true;
        }

        if ($request->is('healthz', 'up', 'favicon.ico')) {
            return true;
        }

        if ($request->isMethod('GET') && $request->is('admin/*') && ! $this->isSensitiveAdminGet($request)) {
            return true;
        }

        return false;
    }

    private function isSensitiveAdminGet(Request $request): bool
    {
        return $request->routeIs(
            'leave.export',
            'report.pdf.download',
            'leave.pdf.download',
            'leave.pdf.print',
            'employee.report.print',
            'credentials.pdf',
        );
    }
}
