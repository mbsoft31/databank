<!--resources/views/exports/worksheet-pdf.blade.php-->
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @font-face {
            font-family: 'Noto Naskh Arabic';
            src: url('{{ resource_path('fonts/NotoNaskhArabic-Regular.ttf') }}') format('truetype');
        }
        body { font-family: 'Noto Naskh Arabic', serif; direction: rtl; }
        .question { margin-bottom: 2rem; page-break-inside: avoid; }
        .options { margin: 1rem 0; }
        .option { margin: 0.5rem 0; }
        .math-content { display: inline-block; }
        .solution { margin-top: 1rem; padding: 1rem; background: #f5f5f5; }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="worksheet">
    @foreach($items as $index => $item)
        <div class="question">
            <h3>السؤال {{ $index + 1 }}</h3>
            <div class="stem">
                {!! safe_math_embed($item->stem_ar) !!}
                @if($item->rendered_latex)
                    <div class="math-content">{!! $item->rendered_latex !!}</div>
                @endif
            </div>

            @if($item->options->isNotEmpty())
                <div class="options">
                    @foreach($item->options as $optIndex => $option)
                        <div class="option">
                            {{ chr(65 + $optIndex) }}. {!! safe_math_embed($option->text_ar) !!}
                            @if($option->rendered_latex)
                                <span class="math-content">{!! $option->rendered_latex !!}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if($include_solutions && $item->solutions->isNotEmpty())
                <div class="solution">
                    <strong>الحل:</strong>
                    @foreach($item->solutions as $solution)
                        <div>{!! safe_math_embed($solution->text_ar) !!}</div>
                        @if($solution->rendered_latex)
                            <div class="math-content">{!! $solution->rendered_latex !!}</div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
</body>
</html>
