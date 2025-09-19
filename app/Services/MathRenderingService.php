<?php
// app/Services/MathRenderingService.php
namespace App\Services;

use App\Models\MathCache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class MathRenderingService
{
    protected string $nodePath;
    protected string $scriptPath;

    public function __construct()
    {
        $this->nodePath = config('services.node.path', 'node');
        $this->scriptPath = resource_path('math/render_math.mjs');
    }

    public function renderLatex(string $latex, bool $displayMode = false, string $engine = 'mathjax'): string
    {
        // Check cache first
        $cached = MathCache::where([
            'latex_input' => $latex,
            'engine' => $engine,
            'display_mode' => $displayMode,
        ])->first();

        if ($cached) {
            return $cached->rendered_output;
        }

        // Render with Node.js
        $input = json_encode([
            'exprs' => [['id' => 'single', 'tex' => $latex, 'display' => $displayMode]]
        ]);

        $result = Process::run([
            $this->nodePath,
            $this->scriptPath,
            "--engine={$engine}"
        ], $input);

        if (!$result->successful()) {
            Log::error('Math rendering failed', ['latex' => $latex, 'error' => $result->errorOutput()]);
            return $latex; // Fallback to raw LaTeX
        }

        $output = json_decode($result->output(), true);
        $rendered = $output['results']['single'] ?? $latex;

        // Cache the result
        MathCache::create([
            'latex_input' => $latex,
            'engine' => $engine,
            'display_mode' => $displayMode,
            'rendered_output' => $rendered,
            'output_format' => $engine === 'mathjax' ? 'svg' : 'html',
        ]);

        return $rendered;
    }
}
