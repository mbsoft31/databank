<?php
// Helper functions - app/helpers.php
use App\Services\MathRenderingService;

if (!function_exists('render_math')) {
    function render_math(string $latex, bool $display = false, string $engine = 'mathjax'): string
    {
        return app(MathRenderingService::class)->renderLatex($latex, $display, $engine);
    }
}

if (!function_exists('safe_math_embed')) {
    function safe_math_embed(string $content): string
    {
        $patterns = [
            '/\\\[(.*?)\\\]/s' => function($matches) {
                return render_math($matches[1], true);
            },
            '/\\\((.*?)\\\)/s' => function($matches) {
                return render_math($matches[1], false);
            },
        ];

        foreach ($patterns as $pattern => $callback) {
            $content = preg_replace_callback($pattern, $callback, $content);
        }

        return $content;
    }
}
