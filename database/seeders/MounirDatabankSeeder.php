<?php
// database/seeders/MounirDatabankSeeder.php
namespace Database\Seeders;

use App\Models\{
    User, Concept, Tag, MediaAsset, ItemDraft, ItemProd, ItemOption,
    ItemHint, ItemSolution, ItemReview, Export, AuditLog, ContentHash, MathCache
};
use App\Enums\{ItemStatus, ExportStatus};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MounirDatabankSeeder extends Seeder
{
    private array $users = [];
    private array $concepts = [];
    private array $tags = [];
    private array $mediaAssets = [];
    private array $conceptByCode = [];

    public function run(): void
    {
        $this->command->info('🌱 Seeding Mounir Databank...');

        $this->seedUsers();
        $this->seedConcepts();
        $this->seedTags();
        $this->seedMediaAssets();
        $this->seedItemDrafts();
        $this->seedPublishedItems();
        $this->seedExports();
        $this->seedMathCache();
        $this->seedAuditLogs();

        $this->command->info('✅ Databank seeding completed!');
    }

    private function seedUsers(): void
    {
        $this->command->info('👤 Creating users...');

        $userData = [
            [
                'name' => 'مدير النظام',
                'email' => 'admin@mounir.edu.dz',
                'role' => 'admin',
                'is_admin' => true,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
            [
                'name' => 'مؤلف المحتوى',
                'email' => 'author@mounir.edu.dz',
                'role' => 'author',
                'is_admin' => false,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
            [
                'name' => 'مراجع المحتوى',
                'email' => 'reviewer@mounir.edu.dz',
                'role' => 'reviewer',
                'is_admin' => false,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
            [
                'name' => 'أستاذ الرياضيات',
                'email' => 'teacher@mounir.edu.dz',
                'role' => 'author',
                'is_admin' => false,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
            [
                'name' => 'المراجع الأول',
                'email' => 'reviewer1@mounir.edu.dz',
                'role' => 'reviewer',
                'is_admin' => false,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ]
        ];

        foreach ($userData as $data) {
            $user = User::create($data);
            $this->users[] = $user;
        }
    }

    private function seedConcepts(): void
    {
        $this->command->info('🧠 Creating concepts...');

        $conceptsData = [
            // Grade 10 - Algebra
            ['code' => 'ALG-10-LINEAR', 'name_ar' => 'المعادلات الخطية', 'grade' => '10', 'strand' => 'algebra'],
            ['code' => 'ALG-10-FACTOR', 'name_ar' => 'التحليل إلى عوامل', 'grade' => '10', 'strand' => 'algebra'],
            ['code' => 'ALG-10-SYSTEMS', 'name_ar' => 'أنظمة المعادلات', 'grade' => '10', 'strand' => 'algebra'],

            // Grade 11 - Functions & Algebra
            ['code' => 'FUN-11-FUNC-BASIC', 'name_ar' => 'مفاهيم الدوال الأساسية', 'grade' => '11', 'strand' => 'functions'],
            ['code' => 'FUN-11-LINEAR', 'name_ar' => 'الدالة الخطية وتمثيلها', 'grade' => '11', 'strand' => 'functions'],
            ['code' => 'ALG-11-QUAD', 'name_ar' => 'المعادلات التربيعية', 'grade' => '11', 'strand' => 'algebra'],

            // Grade 10-11 - Geometry
            ['code' => 'GEO-10-TRI', 'name_ar' => 'المثلثات ومتطابقاتها', 'grade' => '10', 'strand' => 'geometry'],
            ['code' => 'GEO-11-CIRC', 'name_ar' => 'الدائرة والزوايا', 'grade' => '11', 'strand' => 'geometry'],

            // Grade 12 - Calculus
            ['code' => 'CAL-12-DERIV', 'name_ar' => 'المشتقات وقواعدها', 'grade' => '12', 'strand' => 'calculus'],
            ['code' => 'CAL-12-APPL', 'name_ar' => 'تطبيقات المشتقات', 'grade' => '12', 'strand' => 'calculus'],

            // Grade 12 - Statistics
            ['code' => 'STA-12-DESC', 'name_ar' => 'الإحصاء الوصفي', 'grade' => '12', 'strand' => 'statistics'],
        ];

        foreach ($conceptsData as $data) {
            $concept = Concept::create([
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => $this->getEnglishName($data['name_ar']),
                'description_ar' => "وصف مفصل للمفهوم: {$data['name_ar']}",
                'description_en' => "Detailed description of: {$data['name_ar']}",
                'grade' => $data['grade'],
                'strand' => $data['strand'],
                'meta' => [
                    'difficulty_level' => $this->getConceptDifficulty($data['grade']),
                    'prerequisites' => $this->getPrerequisites($data['code']),
                    'learning_objectives' => $this->getLearningObjectives($data['name_ar']),
                ],
            ]);

            $this->concepts[] = $concept;
            $this->conceptByCode[$concept->code] = $concept;
        }
    }

    private function seedTags(): void
    {
        $this->command->info('🏷️ Creating tags...');

        $tagsData = [
            ['code' => 'skill-factor', 'name_ar' => 'مهارة التحليل', 'color' => '#ef4444'],
            ['code' => 'skill-graph', 'name_ar' => 'مهارة التمثيل البياني', 'color' => '#3b82f6'],
            ['code' => 'context-market', 'name_ar' => 'سياق سوق محلي', 'color' => '#22c55e'],
            ['code' => 'form-word', 'name_ar' => 'مسألة لفظية', 'color' => '#f97316'],
            ['code' => 'basic', 'name_ar' => 'أساسي', 'color' => '#64748b'],
            ['code' => 'advanced', 'name_ar' => 'متقدم', 'color' => '#dc2626'],
            ['code' => 'problem-solving', 'name_ar' => 'حل المشكلات', 'color' => '#7c3aed'],
            ['code' => 'real-world', 'name_ar' => 'تطبيق واقعي', 'color' => '#059669'],
            ['code' => 'conceptual', 'name_ar' => 'مفاهيمي', 'color' => '#0891b2'],
            ['code' => 'procedural', 'name_ar' => 'إجرائي', 'color' => '#ea580c'],
        ];

        foreach ($tagsData as $data) {
            $tag = Tag::create([
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => ucwords(str_replace('-', ' ', $data['code'])),
                'color' => $data['color'],
            ]);

            $this->tags[] = $tag;
        }
    }

    private function seedMediaAssets(): void
    {
        $this->command->info('📁 Creating media assets...');

        $mediaData = [
            [
                'filename' => 'triangle_theorem.png',
                'mime_type' => 'image/png',
                'size_bytes' => 256000,
                'storage_path' => 'media/2024/09/triangle_theorem.png',
                'meta' => ['width' => 1200, 'height' => 800, 'type' => 'diagram'],
            ],
            [
                'filename' => 'quadratic_graph.svg',
                'mime_type' => 'image/svg+xml',
                'size_bytes' => 45000,
                'storage_path' => 'media/2024/09/quadratic_graph.svg',
                'meta' => ['width' => 800, 'height' => 600, 'type' => 'graph'],
            ],
            [
                'filename' => 'circle_properties.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 180000,
                'storage_path' => 'media/2024/09/circle_properties.pdf',
                'meta' => ['pages' => 2, 'type' => 'reference'],
            ],
            [
                'filename' => 'derivative_rules.png',
                'mime_type' => 'image/png',
                'size_bytes' => 320000,
                'storage_path' => 'media/2024/09/derivative_rules.png',
                'meta' => ['width' => 1000, 'height' => 1200, 'type' => 'formula_sheet'],
            ],
        ];

        foreach ($mediaData as $data) {
            $asset = MediaAsset::create([
                'filename' => $data['filename'],
                'mime_type' => $data['mime_type'],
                'size_bytes' => $data['size_bytes'],
                'storage_path' => $data['storage_path'],
                'meta' => $data['meta'],
                'created_by' => $this->users[0]->id, // Admin
            ]);

            $this->mediaAssets[] = $asset;
        }
    }

    private function seedItemDrafts(): void
    {
        $this->command->info('📝 Creating item drafts with relationships...');

        $itemCount = 60;
        $statuses = [ItemStatus::Draft, ItemStatus::InReview, ItemStatus::ChangesRequested, ItemStatus::Approved];

        for ($i = 0; $i < $itemCount; $i++) {
            $concept = fake()->randomElement($this->concepts);
            $author = fake()->randomElement(array_slice($this->users, 1, 2));
            $status = fake()->randomElement($statuses);

            // Generate unique, diverse content for each item
            $itemData = $this->generateDiverseItemContent($concept->code, $i);

            $draft = ItemDraft::create([
                'stem_ar' => $itemData['stem'],
                'latex' => $itemData['latex'],
                'item_type' => 'multiple_choice',
                'difficulty' => fake()->randomFloat(1, 1.0, 5.0),
                'status' => $status,
                'created_by' => $author->id,
                'updated_by' => $author->id,
                'meta' => [
                    'source' => 'manual',
                    'estimated_time_minutes' => fake()->numberBetween(2, 10),
                    'cognitive_level' => fake()->randomElement(['remember', 'understand', 'apply', 'analyze']),
                ],
            ]);

            // Attach concepts
            $conceptsToAttach = [$concept->id];
            if (fake()->boolean(20)) {
                $secondConcept = fake()->randomElement($this->concepts);
                if ($secondConcept->id !== $concept->id) {
                    $conceptsToAttach[] = $secondConcept->id;
                }
            }
            $draft->concepts()->attach($conceptsToAttach);

            // Attach tags
            $tagCount = fake()->numberBetween(0, 3);
            if ($tagCount > 0) {
                $tagsToAttach = fake()->randomElements($this->tags, $tagCount);
                $draft->tags()->attach(collect($tagsToAttach)->pluck('id'));
            }

            // Create child records
            $this->createItemOptions($draft, $itemData['correct_answer'], $itemData['distractors']);
            $this->createItemHints($draft, $concept->strand);
            $this->createItemSolutions($draft, $itemData['solution_steps']);

            // Try to create content hash, skip if duplicate
            $this->createContentHashSafely($draft);

            // Create reviews for non-draft items
            if ($status !== ItemStatus::Draft) {
                $this->createItemReview($draft, $status);
            }

            $this->command->getOutput()->write('.');
        }

        $this->command->newLine();
    }

    private function generateDiverseItemContent(string $conceptCode, int $index): array
    {
        // Create more diverse content to avoid hash collisions
        $templates = [
            'ALG-10-LINEAR' => [
                [
                    'stem' => 'أوجد قيمة x في المعادلة: ' . $this->generateLinearEquation($index),
                    'correct_answer' => 'x = ' . (2 + $index % 10),
                    'distractors' => ['x = ' . (1 + $index % 7), 'x = ' . (3 + $index % 8), 'x = ' . (4 + $index % 6)],
                    'solution_steps' => ['نطرح الثابت من الطرفين', 'نقسم على معامل x', 'نحصل على الحل'],
                ],
                [
                    'stem' => 'ما الحل الصحيح للمعادلة: ' . $this->generateLinearEquation($index + 100),
                    'correct_answer' => 'x = ' . (5 + $index % 12),
                    'distractors' => ['x = ' . (6 + $index % 9), 'x = ' . (7 + $index % 11), 'x = ' . (8 + $index % 7)],
                    'solution_steps' => ['نعيد ترتيب المعادلة', 'نجمع الحدود المتشابهة', 'نحل للمتغير x'],
                ]
            ],
            'ALG-11-QUAD' => [
                [
                    'stem' => 'حل المعادلة التربيعية: ' . $this->generateQuadraticEquation($index),
                    'correct_answer' => 'x = 2 أو x = 3',
                    'distractors' => ['x = 1 أو x = 4', 'x = -1 أو x = 5', 'x = 0 أو x = 6'],
                    'solution_steps' => ['نحلل إلى عوامل', 'نساوي كل عامل بالصفر', 'نحصل على الجذور'],
                ],
                [
                    'stem' => 'باستخدام القانون العام، جد جذور المعادلة: ' . $this->generateQuadraticEquation($index + 50),
                    'correct_answer' => 'x = 1 أو x = -2',
                    'distractors' => ['x = 2 أو x = -1', 'x = 3 أو x = 0', 'x = -3 أو x = 1'],
                    'solution_steps' => ['نطبق القانون العام', 'نحسب المميز', 'نحصل على الجذرين'],
                ]
            ],
            'GEO-10-TRI' => [
                [
                    'stem' => 'في مثلث قائم الزاوية، إذا كان طولا الضلعين القائمين ' . (3 + $index % 5) . ' سم و ' . (4 + $index % 6) . ' سم، فما طول الوتر؟',
                    'correct_answer' => (5 + $index % 4) . ' سم',
                    'distractors' => [(6 + $index % 3) . ' سم', (7 + $index % 5) . ' سم', (8 + $index % 4) . ' سم'],
                    'solution_steps' => ['نستخدم نظرية فيثاغورس', 'c² = a² + b²', 'نحسب الجذر التربيعي'],
                ]
            ],
            'GEO-11-CIRC' => [
                [
                    'stem' => 'إذا كانت الزاوية المركزية في دائرة تساوي ' . (60 + $index * 10 % 180) . '°، فما قياس الزاوية المحيطية المقابلة لنفس القوس؟',
                    'correct_answer' => (30 + $index * 5 % 90) . '°',
                    'distractors' => [(45 + $index * 3 % 80) . '°', (60 + $index * 7 % 100) . '°', (90 + $index * 2 % 60) . '°'],
                    'solution_steps' => ['الزاوية المحيطية = نصف الزاوية المركزية', 'نقسم على 2', 'نحصل على النتيجة'],
                ]
            ],
            'CAL-12-DERIV' => [
                [
                    'stem' => 'أوجد مشتقة الدالة f(x) = x² + ' . (2 + $index % 8) . 'x + ' . (1 + $index % 5),
                    'correct_answer' => 'f\'(x) = 2x + ' . (2 + $index % 8),
                    'distractors' => ['f\'(x) = x + ' . (2 + $index % 8), 'f\'(x) = 2x + ' . (1 + $index % 7), 'f\'(x) = x² + ' . (2 + $index % 8)],
                    'solution_steps' => ['نطبق قاعدة القوة', 'نشتق كل حد على حدة', 'الثابت يصبح صفراً'],
                ]
            ],
            'STA-12-DESC' => [
                [
                    'stem' => 'احسب المتوسط الحسابي للقيم التالية: ' . implode('، ', array_map(fn($n) => $n + $index % 10, [4, 7, 9, 12, 15])),
                    'correct_answer' => (9.4 + $index % 5) . '',
                    'distractors' => [(8.2 + $index % 4) . '', (10.6 + $index % 6) . '', (7.8 + $index % 3) . ''],
                    'solution_steps' => ['نجمع جميع القيم', 'نقسم على عدد القيم', 'نحصل على المتوسط'],
                ]
            ],
        ];

        $conceptTemplates = $templates[$conceptCode] ?? [
            [
                'stem' => 'حل المسألة التالية (رقم ' . ($index + 1) . '):',
                'correct_answer' => 'الإجابة الصحيحة',
                'distractors' => ['إجابة خاطئة 1', 'إجابة خاطئة 2', 'إجابة خاطئة 3'],
                'solution_steps' => ['خطوة الحل الأولى', 'خطوة الحل الثانية', 'الوصول للنتيجة'],
            ]
        ];

        $template = fake()->randomElement($conceptTemplates);

        return [
            'stem' => $template['stem'],
            'latex' => $this->generateLatex($conceptCode, $index),
            'correct_answer' => $template['correct_answer'],
            'distractors' => $template['distractors'],
            'solution_steps' => $template['solution_steps'],
        ];
    }

    private function generateLinearEquation(int $seed): string
    {
        $a = 2 + ($seed % 5);
        $b = 3 + ($seed % 7);
        $c = 5 + ($seed % 10);
        return "{$a}x + {$b} = {$c}";
    }

    private function generateQuadraticEquation(int $seed): string
    {
        $a = 1;
        $b = -($seed % 8 + 2);
        $c = $seed % 6 + 1;
        return "x² + {$b}x + {$c} = 0";
    }

    private function generateLatex(string $conceptCode, int $index): ?string
    {
        return match($conceptCode) {
            'ALG-10-LINEAR' => $this->generateLinearEquation($index),
            'ALG-11-QUAD' => $this->generateQuadraticEquation($index),
            'CAL-12-DERIV' => 'f(x) = x^' . (2 + $index % 4) . ' + ' . (1 + $index % 5) . 'x',
            default => null,
        };
    }

    private function createContentHashSafely(ItemDraft $draft): void
    {
        try {
            ContentHash::createForItem($draft);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                // Skip duplicate hashes - this is expected for similar content
                $this->command->warn("Skipped duplicate content hash for item {$draft->id}");
            } else {
                // Re-throw other database errors
                throw $e;
            }
        } catch (\Exception $e) {
            // Log other errors but continue seeding
            $this->command->error("Failed to create content hash for item {$draft->id}: " . $e->getMessage());
        }
    }

    private function seedPublishedItems(): void
    {
        $this->command->info('📚 Creating published items...');

        // Get approved drafts that can be published
        $approvedDrafts = ItemDraft::where('status', ItemStatus::Approved)
            ->with(['concepts', 'tags', 'options', 'hints', 'solutions'])
            ->take(15)
            ->get();

        foreach ($approvedDrafts as $draft) {
            try {
                // Create the published item
                $prod = ItemProd::create([
                    'source_draft_id' => $draft->id,
                    'stem_ar' => $draft->stem_ar,
                    'latex' => $draft->latex,
                    'item_type' => $draft->item_type,
                    'difficulty' => $draft->difficulty,
                    'meta' => array_merge($draft->meta ?? [], [
                        'published_from_draft' => true,
                        'original_author' => $draft->created_by,
                    ]),
                    'published_at' => now(),
                    'published_by' => $this->users[0]->id, // Admin publishes
                ]);

                // Copy relationships
                if ($draft->concepts->isNotEmpty()) {
                    $prod->concepts()->attach($draft->concepts->pluck('id'));
                }

                if ($draft->tags->isNotEmpty()) {
                    $prod->tags()->attach($draft->tags->pluck('id'));
                }

                // Copy options
                foreach ($draft->options as $option) {
                    ItemOption::create([
                        'itemable_id' => $prod->id,
                        'itemable_type' => ItemProd::class,
                        'text_ar' => $option->text_ar,
                        'latex' => $option->latex,
                        'is_correct' => $option->is_correct,
                        'order_index' => $option->order_index,
                    ]);
                }

                // Copy hints
                foreach ($draft->hints as $hint) {
                    ItemHint::create([
                        'itemable_id' => $prod->id,
                        'itemable_type' => ItemProd::class,
                        'text_ar' => $hint->text_ar,
                        'latex' => $hint->latex,
                        'order_index' => $hint->order_index,
                    ]);
                }

                // Copy solutions
                foreach ($draft->solutions as $solution) {
                    ItemSolution::create([
                        'itemable_id' => $prod->id,
                        'itemable_type' => ItemProd::class,
                        'text_ar' => $solution->text_ar,
                        'latex' => $solution->latex,
                        'solution_type' => $solution->solution_type,
                    ]);
                }

                // Update draft status
                $draft->update(['status' => ItemStatus::Published]);

                $this->command->getOutput()->write('.');

            } catch (\Exception $e) {
                $this->command->error("Failed to publish draft {$draft->id}: " . $e->getMessage());
            }
        }

        $this->command->newLine();
    }

    private function seedExports(): void
    {
        $this->command->info('📤 Creating export records...');

        $exportKinds = ['worksheet_pdf', 'worksheet_html', 'worksheet_markdown'];
        $statuses = [ExportStatus::Completed, ExportStatus::Failed, ExportStatus::Pending];

        for ($i = 0; $i < 20; $i++) {
            $status = fake()->randomElement($statuses);
            $user = fake()->randomElement($this->users);
            $kind = fake()->randomElement($exportKinds);

            $export = Export::create([
                'kind' => $kind,
                'params' => [
                    'item_ids' => fake()->randomElements(
                        ItemProd::pluck('id')->toArray(),
                        fake()->numberBetween(5, 15)
                    ),
                    'title' => 'ورقة عمل ' . fake()->randomElement(['الجبر', 'الهندسة', 'التفاضل']),
                    'include_solutions' => fake()->boolean(60),
                    'include_hints' => fake()->boolean(40),
                    'page_layout' => fake()->randomElement(['single_column', 'two_column']),
                    'font_size' => fake()->randomElement(['small', 'medium', 'large']),
                ],
                'status' => $status,
                'file_path' => $status === ExportStatus::Completed
                    ? "exports/worksheets/{$kind}_" . Str::uuid() . ".pdf"
                    : null,
                'error_message' => $status === ExportStatus::Failed
                    ? fake()->randomElement([
                        'Memory limit exceeded during PDF generation',
                        'Invalid LaTeX syntax in content',
                        'Network timeout while processing',
                    ])
                    : null,
                'completed_at' => $status === ExportStatus::Completed
                    ? fake()->dateTimeBetween('-30 days', 'now')
                    : null,
                'requested_by' => $user->id,
            ]);
        }
    }

    private function seedMathCache(): void
    {
        $this->command->info('🧮 Creating math cache entries...');

        $latexExpressions = [
            'x^2 + 3x - 4 = 0',
            '\\frac{x^2 + 2x - 3}{x - 1}',
            '\\int_{0}^{2} x^2 dx',
            '\\sqrt{a^2 + b^2}',
            '\\sum_{i=1}^{n} i^2',
            '\\lim_{x \\to 0} \\frac{\\sin x}{x}',
            'f\'(x) = 2x + 3',
            '\\theta = \\arctan\\left(\\frac{y}{x}\\right)',
            '\\begin{pmatrix} 1 & 2 \\\\ 3 & 4 \\end{pmatrix}',
            'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}',
        ];

        foreach ($latexExpressions as $latex) {
            MathCache::create([
                'latex_input' => $latex,
                'engine' => fake()->randomElement(['mathjax', 'katex']),
                'display_mode' => fake()->boolean(30),
                'rendered_output' => $this->generateMockRenderedOutput($latex),
                'output_format' => 'svg',
            ]);
        }
    }

    private function seedAuditLogs(): void
    {
        $this->command->info('📋 Creating audit logs...');

        $actions = ['created', 'updated', 'reviewed', 'published', 'exported'];
        $models = [ItemDraft::class, ItemProd::class, Export::class];

        for ($i = 0; $i < 100; $i++) {
            $user = fake()->randomElement($this->users);
            $action = fake()->randomElement($actions);
            $modelType = fake()->randomElement($models);

            // Get a random model instance
            $model = $modelType::inRandomOrder()->first();
            if (!$model) continue;

            AuditLog::create([
                'user_id' => $user->id,
                'action' => $action,
                'auditable_type' => $modelType,
                'auditable_id' => $model->id,
                'old_values' => $action === 'updated' ? ['difficulty' => 2.5] : [],
                'new_values' => $action !== 'deleted' ? ['difficulty' => 3.0] : [],
                'ip_address' => fake()->ipv4(),
                'user_agent' => fake()->userAgent(),
            ]);
        }
    }

    // Helper Methods
    private function getEnglishName(string $arabicName): string
    {
        $translations = [
            'المعادلات الخطية' => 'Linear Equations',
            'التحليل إلى عوامل' => 'Factorization',
            // ... add other translations
        ];

        return $translations[$arabicName] ?? 'Mathematical Concept';
    }

    private function getConceptDifficulty(string $grade): string
    {
        return match($grade) {
            '10' => 'intermediate',
            '11' => 'intermediate',
            '12' => 'advanced',
            default => 'basic'
        };
    }

    private function getPrerequisites(string $code): array
    {
        $prerequisites = [
            'ALG-11-QUAD' => ['ALG-10-LINEAR', 'ALG-10-FACTOR'],
            'FUN-11-QUAD' => ['FUN-11-FUNC-BASIC', 'ALG-11-QUAD'],
            'CAL-12-DERIV' => ['FUN-11-FUNC-BASIC', 'ALG-11-QUAD'],
            'CAL-12-APPL' => ['CAL-12-DERIV'],
            'CAL-12-INTEG' => ['CAL-12-DERIV'],
        ];

        return $prerequisites[$code] ?? [];
    }

    private function getLearningObjectives(string $name): array
    {
        return [
            "فهم المفاهيم الأساسية لـ {$name}",
            "تطبيق القواعد والقوانين في حل المسائل",
            "حل مسائل متنوعة ومتدرجة الصعوبة",
        ];
    }

    private function generateItemContent(string $conceptCode): array
    {
        $templates = [
            'ALG-10-LINEAR' => [
                'stem' => 'أوجد قيمة x في المعادلة: 2x + 5 = 13',
                'latex' => '2x + 5 = 13',
                'correct_answer' => 'x = 4',
                'distractors' => ['x = 3', 'x = 5', 'x = 6'],
                'solution_steps' => [
                    'نطرح 5 من الطرفين: 2x = 8',
                    'نقسم على 2: x = 4',
                    'التحقق: 2(4) + 5 = 13 ✓'
                ],
            ],
            'ALG-11-QUAD' => [
                'stem' => 'حل المعادلة التربيعية: x² - 5x + 6 = 0',
                'latex' => 'x^2 - 5x + 6 = 0',
                'correct_answer' => 'x = 2 أو x = 3',
                'distractors' => ['x = 1 أو x = 6', 'x = -2 أو x = -3', 'x = 0 أو x = 5'],
                'solution_steps' => [
                    'نحلل إلى عوامل: (x - 2)(x - 3) = 0',
                    'نساوي كل عامل بالصفر',
                    'الحلول: x = 2 أو x = 3'
                ],
            ],
            'GEO-10-TRI' => [
                'stem' => 'في مثلث قائم الزاوية، إذا كان طولا الساقين 3 و 4، فما طول الوتر؟',
                'latex' => 'c^2 = a^2 + b^2',
                'correct_answer' => '5',
                'distractors' => ['6', '7', '12'],
                'solution_steps' => [
                    'نستخدم نظرية فيثاغورس: c² = a² + b²',
                    'c² = 3² + 4² = 9 + 16 = 25',
                    'c = √25 = 5'
                ],
            ],
        ];

        $default = [
            'stem' => 'حل المسألة التالية:',
            'latex' => 'x + 1 = 2',
            'correct_answer' => 'x = 1',
            'distractors' => ['x = 0', 'x = 2', 'x = 3'],
            'solution_steps' => ['نطرح 1 من الطرفين', 'الحل: x = 1'],
        ];

        return $templates[$conceptCode] ?? $default;
    }

    private function createItemOptions(ItemDraft $draft, string $correct, array $distractors): void
    {
        // Create correct option
        ItemOption::create([
            'itemable_id' => $draft->id,
            'itemable_type' => ItemDraft::class,
            'text_ar' => $correct,
            'is_correct' => true,
            'order_index' => 0,
        ]);

        // Create distractor options
        foreach ($distractors as $index => $distractor) {
            ItemOption::create([
                'itemable_id' => $draft->id,
                'itemable_type' => ItemDraft::class,
                'text_ar' => $distractor,
                'is_correct' => false,
                'order_index' => $index + 1,
            ]);
        }
    }

    private function createItemHints(ItemDraft $draft, string $strand): void
    {
        $hintTemplates = [
            'algebra' => [
                'ابدأ بعزل المتغير في أحد طرفي المعادلة',
                'تذكر قواعد الجمع والطرح للحدود المتشابهة',
                'استخدم العمليات العكسية لحل المعادلة',
            ],
            'geometry' => [
                'ارسم شكلاً توضيحياً يساعدك على فهم المسألة',
                'تذكر القوانين والنظريات المتعلقة بالشكل المعطى',
                'تأكد من وحدات القياس المستخدمة',
            ],
            'calculus' => [
                'استخدم قواعد الاشتقاق الأساسية',
                'تذكر قاعدة السلسلة إذا لزم الأمر',
                'راجع جدول المشتقات للدوال الأساسية',
            ],
        ];

        $hints = $hintTemplates[$strand] ?? $hintTemplates['algebra'];

        foreach (array_slice($hints, 0, fake()->numberBetween(1, 3)) as $index => $hint) {
            ItemHint::create([
                'itemable_id' => $draft->id,
                'itemable_type' => ItemDraft::class,
                'text_ar' => $hint,
                'order_index' => $index,
            ]);
        }
    }

    private function createItemSolutions(ItemDraft $draft, array $steps): void
    {
        ItemSolution::create([
            'itemable_id' => $draft->id,
            'itemable_type' => ItemDraft::class,
            'text_ar' => "الحل:\n" . implode("\n", $steps),
            'solution_type' => 'worked',
        ]);
    }

    private function createContentHash(ItemDraft $draft): void
    {
        try {
            $content = $draft->stem_ar ?? '';

            // Ensure UTF-8 encoding
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'auto');
            }

            // Skip if content is too short or invalid
            if (mb_strlen(trim($content), 'UTF-8') < 5) {
                return; // Skip creating hash for very short content
            }

            $normalizedContent = ContentHash::normalizeContent($content);
            $hash = ContentHash::generateHash($normalizedContent);
            $tokens = ContentHash::generateSimilarityTokens($normalizedContent);

            // Only create if tokens are valid
            if (!empty($tokens)) {
                ContentHash::create([
                    'item_draft_id' => $draft->id,
                    'content_hash' => $hash,
                    'normalized_content' => $normalizedContent,
                    'similarity_tokens' => $tokens,
                ]);
            }
        } catch (\Exception $e) {
            // Log error but continue seeding
            error_log("Failed to create content hash for item {$draft->id}: " . $e->getMessage());
        }
    }

    private function createItemReview(ItemDraft $draft, $status): void
    {
        $reviewer = $this->users[2] ?? $this->users[0]; // Fallback to admin if reviewer not available

        $rubricScores = $this->generateRubricScores($status);
        $overallScore = $this->calculateOverallScore($rubricScores);

        $statusString = is_object($status) ? $status->value : $status;

        $feedback = match($statusString) {
            'approved' => 'سؤال ممتاز وواضح، يحتوي على جميع العناصر المطلوبة',
            'changes_requested' => 'يحتاج تعديل في صياغة السؤال ليكون أكثر وضوحاً',
            'in_review' => null,
            default => 'يحتاج مراجعة شاملة',
        };

        ItemReview::create([
            'item_draft_id' => $draft->id,
            'reviewer_id' => $reviewer->id,
            'status' => match($statusString) {
                'approved' => 'approved',
                'changes_requested' => 'changes_requested',
                'in_review' => 'pending',
                default => 'pending',
            },
            'feedback' => $feedback,
            'rubric_scores' => $rubricScores,
            'overall_score' => $overallScore,
        ]);
    }

    private function generateRubricScores($status): array
    {
        $statusString = is_object($status) ? $status->value : $status;

        $baseRange = match($statusString) {
            'approved' => [4.0, 5.0],
            'changes_requested' => [2.5, 3.5],
            default => [3.0, 4.0],
        };

        return [
            'clarity' => fake()->randomFloat(1, $baseRange[0], $baseRange[1]),
            'accuracy' => fake()->randomFloat(1, $baseRange[0], $baseRange[1]),
            'difficulty' => fake()->randomFloat(1, $baseRange[0], $baseRange[1]),
            'language' => fake()->randomFloat(1, $baseRange[0], $baseRange[1]),
            'completeness' => fake()->randomFloat(1, $baseRange[0], $baseRange[1]),
        ];
    }

    private function calculateOverallScore(array $rubricScores): float
    {
        $weights = [
            'clarity' => 0.25,
            'accuracy' => 0.30,
            'difficulty' => 0.20,
            'language' => 0.15,
            'completeness' => 0.10,
        ];

        $totalScore = 0;
        foreach ($rubricScores as $criterion => $score) {
            $totalScore += $score * ($weights[$criterion] ?? 0.2);
        }

        return round($totalScore, 1);
    }

    private function generateMockRenderedOutput(string $latex): string
    {
        return "<svg xmlns='http://www.w3.org/2000/svg' width='200' height='50'>" .
            "<text x='10' y='30'>" . htmlspecialchars($latex) . "</text>" .
            "</svg>";
    }
}
