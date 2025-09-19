<?php
// app/Models/MathCache.php
namespace App\Models;

use App\Services\MathRenderingService;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{Log};
use Illuminate\Support\Collection;

class MathCache extends Model
{
    use HasFactory;

    protected $table = 'math_cache';

    protected $fillable = [
        'latex_input',
        'engine',
        'display_mode',
        'rendered_output',
        'output_format',
    ];

    protected $casts = [
        'display_mode' => 'boolean',
    ];

    protected $appends = [
        'cache_key',
        'is_expired',
        'render_time_estimate',
        'complexity_score',
    ];

    public const ENGINES = [
        'mathjax' => 'MathJax',
        'katex' => 'KaTeX',
        'tex' => 'LaTeX',
    ];

    public const OUTPUT_FORMATS = [
        'svg' => 'SVG',
        'html' => 'HTML',
        'png' => 'PNG',
        'mathml' => 'MathML',
    ];

    public const CACHE_DURATION = 7 * 24 * 60 * 60; // 7 days in seconds

    // Scopes
    public function scopeByEngine(Builder $query, string $engine): Builder
    {
        return $query->where('engine', $engine);
    }

    public function scopeDisplayMode(Builder $query): Builder
    {
        return $query->where('display_mode', true);
    }

    public function scopeInlineMode(Builder $query): Builder
    {
        return $query->where('display_mode', false);
    }

    public function scopeByOutputFormat(Builder $query, string $format): Builder
    {
        return $query->where('output_format', $format);
    }

    public function scopeExpired(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeComplex(Builder $query): Builder
    {
        return $query->where(function($q) {
            $q->where('latex_input', 'LIKE', '%\\int%')
                ->orWhere('latex_input', 'LIKE', '%\\sum%')
                ->orWhere('latex_input', 'LIKE', '%\\lim%')
                ->orWhere('latex_input', 'LIKE', '%\\frac%')
                ->orWhere('latex_input', 'LIKE', '%\\sqrt%')
                ->orWhere('latex_input', 'LIKE', '%\\begin{%');
        });
    }

    public function scopePopular(Builder $query, int $limit = 10): Builder
    {
        return $query->selectRaw('latex_input, engine, display_mode, COUNT(*) as usage_count')
            ->groupBy(['latex_input', 'engine', 'display_mode'])
            ->orderBy('usage_count', 'desc')
            ->limit($limit);
    }

    // Accessors
    public function getCacheKeyAttribute(): string
    {
        return $this->generateCacheKey($this->latex_input, $this->engine, $this->display_mode);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->created_at->addSeconds(self::CACHE_DURATION)->isPast();
    }

    public function getRenderTimeEstimateAttribute(): int
    {
        // Estimate render time in milliseconds based on complexity
        $baseTime = match($this->engine) {
            'mathjax' => 150,
            'katex' => 50,
            'tex' => 300,
            default => 100,
        };

        $complexity = $this->complexity_score;
        return intval($baseTime * (1 + $complexity / 10));
    }

    public function getComplexityScoreAttribute(): int
    {
        $latex = $this->latex_input;
        $score = 0;

        // Basic complexity factors
        $score += substr_count($latex, '\\') * 2; // LaTeX commands
        $score += substr_count($latex, '{') * 1; // Braces
        $score += substr_count($latex, '_') * 1; // Subscripts
        $score += substr_count($latex, '^') * 1; // Superscripts

        // Complex structures
        $score += substr_count($latex, '\\frac') * 3;
        $score += substr_count($latex, '\\sqrt') * 2;
        $score += substr_count($latex, '\\int') * 4;
        $score += substr_count($latex, '\\sum') * 4;
        $score += substr_count($latex, '\\lim') * 3;
        $score += substr_count($latex, '\\begin') * 5;
        $score += substr_count($latex, '\\matrix') * 4;

        // Display mode adds complexity
        if ($this->display_mode) $score += 2;

        return min(100, $score); // Cap at 100
    }

    // Helper Methods
    public function isValid(): bool
    {
        return !empty($this->rendered_output) && !str_contains($this->rendered_output, 'error');
    }

    public function hasErrors(): bool
    {
        return str_contains(strtolower($this->rendered_output), 'error') ||
            str_contains(strtolower($this->rendered_output), 'invalid') ||
            empty($this->rendered_output);
    }

    public function getSize(): int
    {
        return strlen($this->rendered_output);
    }

    public function getSizeFormatted(): string
    {
        $bytes = $this->getSize();
        $units = ['B', 'KB', 'MB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function refresh(): bool
    {
        try {
            $service = app(MathRenderingService::class);
            $newOutput = $service->renderLatex($this->latex_input, $this->display_mode, $this->engine);

            if (!empty($newOutput)) {
                $this->update(['rendered_output' => $newOutput]);
                return true;
            }
        } catch (Exception $e) {
            Log::error('Failed to refresh math cache', [
                'id' => $this->id,
                'latex' => $this->latex_input,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    public function duplicate(string $newEngine): ?self
    {
        try {
            $service = app(MathRenderingService::class);
            $newOutput = $service->renderLatex($this->latex_input, $this->display_mode, $newEngine);

            return static::create([
                'latex_input' => $this->latex_input,
                'engine' => $newEngine,
                'display_mode' => $this->display_mode,
                'rendered_output' => $newOutput,
                'output_format' => $newEngine === 'mathjax' ? 'svg' : 'html',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to duplicate math cache entry', [
                'original_id' => $this->id,
                'new_engine' => $newEngine,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getPreview(int $length = 100): string
    {
        if ($this->output_format === 'svg') {
            // Extract text content from SVG for preview
            $text = strip_tags($this->rendered_output);
            return Str::limit($text, $length);
        }

        return Str::limit(strip_tags($this->rendered_output), $length);
    }

    public function getMetadata(): array
    {
        return [
            'id' => $this->id,
            'latex_input' => $this->latex_input,
            'engine' => $this->engine,
            'display_mode' => $this->display_mode,
            'output_format' => $this->output_format,
            'complexity_score' => $this->complexity_score,
            'render_time_estimate' => $this->render_time_estimate,
            'size_bytes' => $this->getSize(),
            'size_formatted' => $this->getSizeFormatted(),
            'is_valid' => $this->isValid(),
            'has_errors' => $this->hasErrors(),
            'is_expired' => $this->is_expired,
            'cached_at' => $this->created_at->toISOString(),
            'expires_at' => $this->created_at->addSeconds(self::CACHE_DURATION)->toISOString(),
        ];
    }

    // Static Methods
    public static function findCached(string $latex, string $engine = 'mathjax', bool $displayMode = false): ?self
    {
        return static::where([
            'latex_input' => $latex,
            'engine' => $engine,
            'display_mode' => $displayMode,
        ])->first();
    }

    public static function getOrRender(string $latex, string $engine = 'mathjax', bool $displayMode = false): string
    {
        // Try to find existing cache
        $cached = static::findCached($latex, $engine, $displayMode);

        if ($cached && !$cached->is_expired && $cached->isValid()) {
            return $cached->rendered_output;
        }

        // Render new
        try {
            $service = app(MathRenderingService::class);
            $output = $service->renderLatex($latex, $displayMode, $engine);

            if ($cached) {
                // Update existing cache
                $cached->update(['rendered_output' => $output]);
            } else {
                // Create new cache entry
                static::create([
                    'latex_input' => $latex,
                    'engine' => $engine,
                    'display_mode' => $displayMode,
                    'rendered_output' => $output,
                    'output_format' => $engine === 'mathjax' ? 'svg' : 'html',
                ]);
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Math rendering failed', [
                'latex' => $latex,
                'engine' => $engine,
                'error' => $e->getMessage()
            ]);
            return $latex; // Fallback to raw LaTeX
        }
    }

    public static function generateCacheKey(string $latex, string $engine = 'mathjax', bool $displayMode = false): string
    {
        return 'math_cache_' . md5($latex . $engine . ($displayMode ? '1' : '0'));
    }

    public static function getEngineOptions(): array
    {
        return self::ENGINES;
    }

    public static function getFormatOptions(): array
    {
        return self::OUTPUT_FORMATS;
    }

    public static function getStatistics(): array
    {
        return [
            'total_entries' => static::count(),
            'by_engine' => static::selectRaw('engine, COUNT(*) as count')
                ->groupBy('engine')
                ->pluck('count', 'engine'),
            'by_format' => static::selectRaw('output_format, COUNT(*) as count')
                ->groupBy('output_format')
                ->pluck('count', 'output_format'),
            'display_vs_inline' => [
                'display' => static::where('display_mode', true)->count(),
                'inline' => static::where('display_mode', false)->count(),
            ],
            'complex_entries' => static::complex()->count(),
            'expired_entries' => static::expired()->count(),
            'total_size_mb' => round(
                static::selectRaw('SUM(LENGTH(rendered_output)) as total_size')->value('total_size') / 1048576,
                2
            ),
            'avg_complexity' => round(static::selectRaw('AVG(
                (LENGTH(latex_input) - LENGTH(REPLACE(latex_input, "\\\\", ""))) / 2 +
                (LENGTH(latex_input) - LENGTH(REPLACE(latex_input, "{", ""))) +
                (LENGTH(latex_input) - LENGTH(REPLACE(latex_input, "\\\\frac", ""))) * 3
            ) as avg_complexity')->value('avg_complexity'), 2),
        ];
    }

    public static function cleanup(int $days = 30): int
    {
        return static::expired($days)->delete();
    }

    public static function warmCache(array $latexExpressions, array $engines = ['mathjax'], array $modes = [false]): int
    {
        $cached = 0;

        foreach ($latexExpressions as $latex) {
            foreach ($engines as $engine) {
                foreach ($modes as $displayMode) {
                    if (!static::findCached($latex, $engine, $displayMode)) {
                        static::getOrRender($latex, $engine, $displayMode);
                        $cached++;
                    }
                }
            }
        }

        return $cached;
    }

    public static function getMostUsed(int $limit = 10): Collection
    {
        return static::selectRaw('latex_input, engine, display_mode, COUNT(*) as usage_count')
            ->groupBy(['latex_input', 'engine', 'display_mode'])
            ->orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public static function getRecentActivity(int $hours = 24): Collection
    {
        return static::recent($hours)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00") as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
    }
}
