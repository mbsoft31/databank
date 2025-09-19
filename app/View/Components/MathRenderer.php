<?php
// app/View/Components/MathRenderer.php
namespace App\View\Components;

use App\Services\MathRenderingService;
use Illuminate\View\Component;

class MathRenderer extends Component
{
    public function __construct(
        public string $latex,
        public bool $display = false,
        public string $engine = 'mathjax'
    ) {}

    public function render()
    {
        $mathService = app(MathRenderingService::class);
        $rendered = $mathService->renderLatex($this->latex, $this->display, $this->engine);

        return view('components.math-renderer', compact('rendered'));
    }
}
