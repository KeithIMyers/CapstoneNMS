<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Comments;
use App\Models\News;
use App\Models\Pages;
use App\Models\Settings;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample content for the homepage. Idempotent — safe to re-run; skips
 * anything already seeded (matched by slug). Uses bundled SVG hero
 * images from /site/img/seed/* so there are no external image deps.
 */
class SampleContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettingsDefaults();
        $author = $this->ensureAuthor();
        $categories = $this->seedCategories();
        $this->seedTags();
        $this->seedPages();
        $articles = $this->seedArticles($author, $categories);
        $this->seedComments($articles, $author);

        $this->command?->info('Sample content seeded.');
    }

    private function seedSettingsDefaults(): void
    {
        $defaults = [
            'site_name'        => 'My News Site',
            'site_description' => 'Independent reporting on politics, business, technology, and culture in the United States.',
            'site_email'       => 'editor@example.com',
            'copyright_text'   => '© '.date('Y').' My News Site',
            'site_storage'     => 'public',
            'rtl'              => '',
        ];
        foreach ($defaults as $key => $value) {
            Settings::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function ensureAuthor(): User
    {
        $editor = User::where('role', 'admin')->orderBy('id')->first();
        if ($editor) {
            return $editor;
        }
        // Fallback: a non-staff demo author. Should rarely apply because the
        // seeder is run after make:admin in normal flows.
        // firstOrCreate's second argument goes through fill(), which
        // would silently drop role/status now that they're outside
        // $fillable. Build the row explicitly via forceFill instead.
        $existing = User::where('email', 'sample-editor@example.com')->first();
        if ($existing) return $existing;

        $u = new User;
        $u->forceFill([
            'email'    => 'sample-editor@example.com',
            'name'     => 'Sample Editor',
            'role'     => 'editor',
            'status'   => 1,
            'password' => Hash::make(Str::random(32)),
        ])->save();
        return $u;
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $tree = [
            ['name' => 'Politics', 'slug' => 'politics', 'description' => 'Power, policy, and the people behind both.', 'order' => 1, 'children' => [
                ['name' => 'White House', 'slug' => 'white-house'],
                ['name' => 'Congress',    'slug' => 'congress'],
                ['name' => 'Elections',   'slug' => 'elections'],
            ]],
            ['name' => 'Business', 'slug' => 'business', 'description' => 'Markets, money, and the economy.', 'order' => 2],
            ['name' => 'Tech',     'slug' => 'tech', 'description' => 'Software, hardware, and the people building it.', 'order' => 3],
            ['name' => 'Sports',   'slug' => 'sports', 'description' => 'Wins, losses, and the long shots in between.', 'order' => 4, 'children' => [
                ['name' => 'NFL', 'slug' => 'nfl'],
                ['name' => 'NBA', 'slug' => 'nba'],
            ]],
            ['name' => 'Health',   'slug' => 'health', 'description' => 'Medicine, public health, and well-being.', 'order' => 5],
            ['name' => 'World',    'slug' => 'world', 'description' => 'Reporting from outside the United States.', 'order' => 6],
            ['name' => 'Opinion',  'slug' => 'opinion', 'description' => 'Argument, analysis, and editorial voices.', 'order' => 7],
        ];

        $bySlug = [];
        foreach ($tree as $row) {
            $parent = Category::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'cat_order' => $row['order'],
                    'status' => 1,
                    'parent_id' => null,
                ]
            );
            $bySlug[$row['slug']] = $parent;

            foreach ($row['children'] ?? [] as $child) {
                $node = Category::updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'name' => $child['name'],
                        'cat_order' => 0,
                        'status' => 1,
                        'parent_id' => $parent->id,
                    ]
                );
                $bySlug[$child['slug']] = $node;
            }
        }

        return $bySlug;
    }

    private function seedTags(): void
    {
        $tags = ['economy', 'congress', 'election', 'tech', 'ai', 'climate', 'health', 'sports', 'global', 'opinion', 'analysis', 'feature'];
        foreach ($tags as $name) {
            Tag::findOrCreateByName($name);
        }
    }

    private function seedPages(): void
    {
        $pages = [
            [
                'page_title' => 'About this site',
                'page_slug' => 'about',
                'page_order' => 1,
                'page_content' => '<p>This site is an example newsroom built with CapstoneNMS covering U.S. politics, business, technology, sports, health, and the world beyond. This site is in active development; the homepage you are looking at is a preview seeded with sample stories.</p><p>Editorial inquiries: <a href="/pages/contact-us">contact us</a>.</p>',
            ],
            [
                'page_title' => 'Privacy',
                'page_slug' => 'privacy',
                'page_order' => 2,
                'page_content' => '<p>This is placeholder privacy content. Replace with a real privacy notice before launch — explain what data you collect (analytics, account info, comments), who you share it with, and how readers can request deletion.</p>',
            ],
            [
                'page_title' => 'Terms',
                'page_slug' => 'terms',
                'page_order' => 3,
                'page_content' => '<p>This is placeholder terms-of-use content. Replace with the actual terms governing use of the site, comments, and any subscriptions.</p>',
            ],
        ];

        foreach ($pages as $p) {
            Pages::updateOrCreate(['page_slug' => $p['page_slug']], array_merge($p, ['status' => 1]));
        }
    }

    /** @return array<string, News> */
    private function seedArticles(User $author, array $categories): array
    {
        $now = Carbon::now();

        $articles = [
            [
                'slug' => 'congress-passes-energy-bill',
                'title' => 'Congress passes long-stalled energy bill in late-night vote',
                'subtitle' => 'The bipartisan package authorizes funding for grid modernization, advanced reactors, and a federal permitting overhaul that supporters say is overdue.',
                'kicker' => 'BREAKING',
                'dateline' => 'WASHINGTON',
                'category' => 'congress',
                'image' => '/site/img/seed/breaking.svg',
                'image_alt' => 'Stylized "BREAKING" graphic in red',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['congress', 'economy', 'climate'],
                'is_breaking' => true,
                'is_featured' => true,
                'published_offset' => -1,            // hours ago
                'views' => 4280,
                'body' => <<<'HTML'
<p>After two years of competing proposals and one collapsed conference report, the Senate cleared the Modernization and Resilience Act late Thursday by a margin of 71 to 27, sending it to the House for a Friday vote. The package authorizes <strong>$94 billion</strong> in new spending over five years, with the bulk directed at upgrading regional transmission lines and expanding the federal loan-guarantee program for advanced nuclear reactors.</p>
<p>The bill's passage caps an unusually quiet floor week, during which both leaders agreed to limit amendments to a managed list of fourteen. Senators from both parties praised the procedural detente; one veteran staffer privately called it "the closest thing to regular order we've had since 2019."</p>
<h2>What's in the package</h2>
<p>Beyond grid spending, the act creates a new federal permitting office tasked with consolidating agency review of interstate transmission projects. Supporters say the office will trim approval timelines from a current average of five years to roughly eighteen months. Critics, including a vocal minority of progressives, argue the streamlined process risks short-circuiting environmental review.</p>
<p>A separate title funds workforce training for grid technicians, with priority hiring in former coal-producing counties.</p>
<h3>What happens next</h3>
<p>The House is expected to vote Friday afternoon. Speaker Patricia Holloway told reporters Thursday that she has commitments from "more than enough" of her caucus to send the bill to the president by the weekend.</p>
HTML,
            ],
            [
                'slug' => 'ai-startup-pacific-coast-funding',
                'title' => 'Pacific Coast AI startup raises $180 million to take on incumbents',
                'subtitle' => 'The funding round, led by a sovereign wealth fund and two long-time growth investors, values the three-year-old company at $1.4 billion.',
                'kicker' => 'TECH',
                'dateline' => 'SAN FRANCISCO',
                'category' => 'tech',
                'image' => '/site/img/seed/tech.svg',
                'image_alt' => 'Abstract data lines on a dark background',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['ai', 'tech', 'economy'],
                'is_featured' => true,
                'published_offset' => -3,
                'views' => 2118,
                'body' => <<<'HTML'
<p>Acme Reasoning, an AI inference startup that pitches itself as a "frontier-quality model at one-tenth the cost," said Wednesday that it has closed a Series C round of $180 million. The round was co-led by Pacific Coast Capital and an unnamed Gulf-region sovereign fund; existing investors Bedrock Ventures and Compass Partners participated.</p>
<p>The company says it now has more than 600 paying enterprise customers and an annualized run rate "approaching $80 million." Founder and chief executive Layla Rios declined to break out gross margins but said the company is "on a glide path to profitability without raising again."</p>
<h2>The bigger picture</h2>
<p>The deal comes amid a renewed wave of capital flowing to companies positioning themselves as cheaper, more controllable alternatives to the largest model providers. Most of the new money is going into specialized inference hardware partnerships and sales hires rather than additional model training.</p>
<p>Investors say the calculus has shifted. "Two years ago you had to be in the model race," said one limited partner who reviewed the round. "Now the question is who's going to do the unsexy work of running these things in production for boring industries."</p>
HTML,
            ],
            [
                'slug' => 'fed-holds-rates-housing-market',
                'title' => 'Fed holds rates steady but signals a single cut later this year',
                'subtitle' => 'Officials cited a cooling labor market but stopped short of a more aggressive easing path traders had priced in.',
                'kicker' => 'BUSINESS',
                'dateline' => 'WASHINGTON',
                'category' => 'business',
                'image' => '/site/img/seed/business.svg',
                'image_alt' => 'Rising chart line on a green gradient',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['economy', 'analysis'],
                'is_featured' => true,
                'published_offset' => -8,
                'views' => 1543,
                'body' => <<<'HTML'
<p>The Federal Reserve left its benchmark rate unchanged at the conclusion of its two-day policy meeting Wednesday but penciled in one rate cut before year's end, a notably cautious signal compared with what futures markets had priced in over the prior month.</p>
<p>Chair Daniel Whitfield, in a press conference following the decision, said the Federal Open Market Committee remains "patient and data dependent." He highlighted softening payroll growth and continued progress on services inflation but warned that the labor market remains tighter than at any point in the prior business cycle.</p>
<p>Stocks slipped on the news, with the S&P 500 closing 0.6% lower; the two-year Treasury yield rose seven basis points.</p>
<h2>Housing market reaction</h2>
<p>Mortgage rates barely moved on the announcement, holding near 6.4% on the 30-year fixed product, according to lender survey data. The National Association of Home Builders said in a statement that "any meaningful relief for would-be buyers remains some quarters away."</p>
HTML,
            ],
            [
                'slug' => 'nfl-week-12-recap',
                'title' => 'Week 12 takeaways: rookies shine, contenders stumble',
                'subtitle' => 'Three first-year passers had their best games of the season, while two playoff favorites left the weekend with new injury concerns.',
                'kicker' => 'NFL',
                'category' => 'nfl',
                'image' => '/site/img/seed/sports.svg',
                'image_alt' => 'Stylized football illustration in orange tones',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['sports'],
                'published_offset' => -14,
                'views' => 920,
                'body' => <<<'HTML'
<p>The story of Week 12 was the rookies. After a quiet October, three first-year quarterbacks turned in the best statistical games of their young careers, combining for nine touchdown passes and zero interceptions across Sunday's slate.</p>
<p>The flip side: two of the league's top contenders left their respective games with significant injuries to starting offensive linemen. One head coach, asked whether the timing was concerning, said simply, "We don't have a lot of margin left."</p>
<h2>The shape of the playoff race</h2>
<p>With six weeks remaining, the wild-card race in both conferences is now a five-team scrum, the tightest at this point in the season since 2017.</p>
HTML,
            ],
            [
                'slug' => 'global-summit-trade-deal',
                'title' => 'Global summit yields tentative trade framework after marathon talks',
                'subtitle' => 'Negotiators emerged with a 14-page joint statement covering tariffs on critical minerals, digital services, and agricultural quotas.',
                'kicker' => 'WORLD',
                'dateline' => 'GENEVA',
                'category' => 'world',
                'image' => '/site/img/seed/world.svg',
                'image_alt' => 'Stylized globe with grid lines',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['global', 'economy', 'analysis'],
                'published_offset' => -22,
                'views' => 712,
                'body' => <<<'HTML'
<p>After three days of stop-and-start negotiations that ran past midnight in two consecutive sessions, ministers from twenty-six nations announced a tentative framework covering tariff schedules on critical minerals, digital services, and a narrow set of agricultural quotas.</p>
<p>The agreement is non-binding and must still survive ratification at home for several signatories, where domestic industries have already begun lobbying against specific clauses. But several diplomats called the framework's mere existence a meaningful breakthrough after a year in which multilateral talks repeatedly broke down.</p>
HTML,
            ],
            [
                'slug' => 'walking-meets-cardio-study',
                'title' => 'A new study finds brisk walking matches cardio benefits of running',
                'subtitle' => 'Researchers tracked more than 12,000 adults over four years and concluded that intensity matters more than mileage.',
                'kicker' => 'HEALTH',
                'category' => 'health',
                'image' => '/site/img/seed/health.svg',
                'image_alt' => 'Heart silhouette over a pink gradient',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['health', 'feature'],
                'published_offset' => -30,
                'views' => 612,
                'body' => <<<'HTML'
<p>A four-year longitudinal study published this week reports that adults who regularly walked at a brisk pace — defined as roughly 4 miles per hour or faster — saw cardiovascular outcomes statistically comparable to a matched cohort of recreational runners.</p>
<p>The takeaway is not that running is overrated, the lead author cautioned. It is that intensity matters more than distance, and that a daily 30-minute walk done with purpose may deliver most of the meaningful benefit for the vast majority of people.</p>
<h2>What "brisk" means in practice</h2>
<p>For most adults, brisk walking is the pace at which conversation becomes audibly effortful. The study used wrist-worn accelerometer data rather than self-reports, which the authors argue gives the result more weight than past walking research.</p>
HTML,
            ],
            [
                'slug' => 'opinion-modernizing-civic-tech',
                'title' => 'Opinion: It is time to take civic tech seriously',
                'subtitle' => 'Local governments are still running 1990s software while their citizens expect Amazon-grade interfaces. We can do better.',
                'kicker' => 'OPINION',
                'category' => 'opinion',
                'image' => '/site/img/seed/opinion.svg',
                'image_alt' => 'Stylized quotation mark on a dark background',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['opinion', 'tech'],
                'published_offset' => -36,
                'views' => 388,
                'body' => <<<'HTML'
<p>Renewing your driver's license shouldn't take longer than ordering takeout. And yet for millions of Americans, the experience of interacting with a city or county online still feels like a 1998 government agency invented an extranet — because, frequently, that is exactly what happened.</p>
<p>The problem is not money. Local governments collectively spend tens of billions on software every year. The problem is procurement, an arena that systematically rewards stagnation, punishes thoughtful design, and almost always picks the lowest-bid incumbent who is least equipped to do the work well.</p>
<p>Fixing this isn't ideologically charged; it's mostly logistical. The next administration of any color should treat civic-tech reform with the same seriousness as physical infrastructure.</p>
HTML,
            ],
            [
                'slug' => 'election-poll-watchers-guide',
                'title' => 'A reader\'s guide to early-cycle election polling',
                'subtitle' => 'Three rules of thumb for evaluating the avalanche of horse-race numbers about to land.',
                'kicker' => 'ANALYSIS',
                'category' => 'elections',
                'image' => '/site/img/seed/politics.svg',
                'image_alt' => 'Abstract bars in shades of blue',
                'image_credit' => 'CapstoneNMS sample',
                'tags' => ['election', 'analysis', 'feature'],
                'is_featured' => true,
                'published_offset' => -48,
                'views' => 488,
                'body' => <<<'HTML'
<p>Polling season is here. Over the next few weeks you will see headlines breathlessly reporting candidate X up by three or candidate Y down by five, often based on surveys with margins of error wider than the reported leads. A few habits will save you a lot of confusion.</p>
<h2>Rule one: aggregate, don't cherry-pick</h2>
<p>Any single poll is a noisy snapshot. The signal lives in the average — and in how that average is moving over a longer baseline.</p>
<h2>Rule two: read the methodology</h2>
<p>Online opt-in panels, automated phone calls, and live-interviewer surveys are not interchangeable. The methodology section is short and explains a lot.</p>
<h2>Rule three: ignore the early conventional wisdom</h2>
<p>Most pundit-takes about what a single early poll "means" age poorly. Wait for the trend.</p>
HTML,
            ],
            // Scheduled — appears as upcoming on the dashboard
            [
                'slug' => 'tech-newsletter-relaunch',
                'title' => 'Behind the scenes: relaunching our tech newsletter',
                'subtitle' => 'A look at the editorial choices going into the next season of our weekly send.',
                'kicker' => 'INSIDE',
                'category' => 'tech',
                'image' => '/site/img/seed/tech.svg',
                'image_alt' => 'Abstract data lines',
                'tags' => ['tech', 'feature'],
                'editorial_status' => News::STATUS_SCHEDULED,
                'published_offset' => 18,             // 18h in the future
                'views' => 0,
                'body' => '<p>This article is scheduled and will publish automatically at the time set in the editorial workflow.</p>',
            ],
            // Draft — to populate the "drafts in flight" stat
            [
                'slug' => 'draft-economy-outlook',
                'title' => 'Draft: Economy outlook for the back half',
                'subtitle' => 'Working draft — do not promote.',
                'category' => 'business',
                'image' => null,
                'tags' => ['economy', 'analysis'],
                'editorial_status' => News::STATUS_DRAFT,
                'views' => 0,
                'body' => '<p>This is a draft article that has not yet been published. It exists so the dashboard "drafts in flight" widget has something to count.</p>',
            ],
        ];

        $created = [];

        foreach ($articles as $a) {
            $cat = $categories[$a['category']] ?? null;
            if (! $cat) {
                continue;
            }

            $publishedAt = isset($a['published_offset'])
                ? Carbon::now()->addHours($a['published_offset'])
                : Carbon::now();

            $editorialStatus = $a['editorial_status'] ?? News::STATUS_PUBLISHED;

            $news = News::updateOrCreate(
                ['slug' => $a['slug']],
                [
                    'title' => $a['title'],
                    'subtitle' => $a['subtitle'] ?? null,
                    'kicker' => $a['kicker'] ?? null,
                    'dateline' => $a['dateline'] ?? null,
                    'excerpt' => $a['subtitle'] ?? Str::limit(strip_tags($a['body']), 200),
                    'meta_title' => null,
                    'meta_description' => $a['subtitle'] ?? null,
                    'content' => $a['body'],
                    'image' => $a['image'] ?? null,
                    'image_alt' => $a['image_alt'] ?? null,
                    'image_credit' => $a['image_credit'] ?? null,
                    'tags' => isset($a['tags']) ? implode(',', $a['tags']) : null,
                    'category_id' => $cat->id,
                    'user_id' => $author->id,
                    'editorial_status' => $editorialStatus,
                    'published_at' => $editorialStatus === News::STATUS_DRAFT ? null : $publishedAt,
                    'is_featured' => $a['is_featured'] ?? false,
                    'date' => $publishedAt->getTimestamp(),
                    'views' => $a['views'] ?? 0,
                ]
            );

            // Optional fields that aren't always present.
            if (! empty($a['is_breaking'])) {
                $news->forceFill([
                    'is_breaking' => true,
                    'breaking_until' => Carbon::now()->addHours(12),
                ])->save();
            }

            // Sync tags via the pivot using the new Tag model.
            if (! empty($a['tags'])) {
                $tagIds = collect($a['tags'])
                    ->map(fn ($name) => Tag::findOrCreateByName($name)->id)
                    ->all();
                $news->tagsRelation()->sync($tagIds);
            }

            $created[$a['slug']] = $news;
        }

        return $created;
    }

    private function seedComments(array $articles, User $fallbackUser): void
    {
        $comments = [
            ['slug' => 'congress-passes-energy-bill', 'body' => 'Long overdue. Curious whether the permitting office actually staffs up before the next administration takes over.'],
            ['slug' => 'congress-passes-energy-bill', 'body' => 'Glad to see the workforce-training piece survived conference.'],
            ['slug' => 'ai-startup-pacific-coast-funding', 'body' => '"Frontier-quality model at one-tenth the cost" is doing a lot of work in this article. Receipts please.'],
            ['slug' => 'walking-meets-cardio-study', 'body' => 'My knees thank you for this report.'],
        ];

        foreach ($comments as $c) {
            $article = $articles[$c['slug']] ?? null;
            if (! $article) {
                continue;
            }
            Comments::firstOrCreate(
                ['post_id' => $article->id, 'content' => $c['body']],
                [
                    'user_id' => $fallbackUser->id,
                    'status' => Comments::STATUS_APPROVED,
                ]
            );
        }
    }
}
