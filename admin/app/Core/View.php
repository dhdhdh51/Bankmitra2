<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer with a single layout wrapper.
 *
 * All output in templates must go through e() / the helper functions in
 * helpers.php - there is no auto-escaping engine here, so escaping is explicit
 * and reviewable.
 */
final class View
{
    /** @var array<string,mixed> Values shared with every template. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Renders a template inside layouts/app.php.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): never
    {
        echo self::capture($template, $data, $layout);
        exit;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture(string $template, array $data = [], ?string $layout = null): string
    {
        $data = array_merge(self::$shared, self::requestScopedData(), $data);

        $content = self::renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * Renders a partial and returns it as a string (for use inside templates).
     *
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, array_merge(self::$shared, $data));
    }

    /**
     * Renders a template file with the given data as local variables.
     *
     * Locals here are deliberately prefixed with `__lrms`. extract() with
     * EXTR_SKIP will not overwrite a variable that already exists in scope, so a
     * plain local named `$data` or `$template` would silently shadow the view key
     * of the same name and the template would receive the wrong value.
     *
     * @param array<string,mixed> $__lrmsData
     */
    private static function renderFile(string $__lrmsTemplate, array $__lrmsData): string
    {
        $__lrmsFile = ROOT_PATH . '/views/' . ltrim($__lrmsTemplate, '/') . '.php';
        if (!is_file($__lrmsFile)) {
            throw new \RuntimeException("View not found: {$__lrmsTemplate}");
        }

        // Templates read the data keys as local variables.
        extract($__lrmsData, EXTR_SKIP);

        ob_start();
        try {
            include $__lrmsFile;
        } catch (\Throwable $__lrmsError) {
            ob_end_clean();
            throw $__lrmsError;
        }

        return (string) ob_get_clean();
    }

    /**
     * Flash messages, validation errors and old input are consumed once per
     * render so templates can rely on them being present.
     *
     * @return array<string,mixed>
     */
    private static function requestScopedData(): array
    {
        return [
            'flash'      => Session::takeFlash(),
            'errors'     => Session::takeErrors(),
            'old'        => Session::takeOldInput(),
            'authUser'   => Auth::user(),
            'csrfToken'  => Auth::check() ? Csrf::token() : '',
        ];
    }
}
