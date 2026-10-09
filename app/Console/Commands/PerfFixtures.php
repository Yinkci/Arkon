<?php

namespace App\Console\Commands;

use App\Arkon\Components\PatternLibrary;
use App\Arkon\Design\ComponentService;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Pages\PageService;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Builds the three published pages the performance measurements use (docs/PERFORMANCE.md):
 * a hero with a large photo, an image-heavy page and a deeply nested layout. Only for the
 * isolated test databases (the e2e one in practice): it refuses any other database.
 */
class PerfFixtures extends Command
{
    protected $signature = 'arkon:perf-fixtures {--email= : The owner the pages are made by}';

    protected $description = 'Create and publish the performance test pages (test databases only)';

    public function handle(PageService $pages, MediaService $media): int
    {
        $database = (string) config('database.connections.pgsql.database');
        if (! preg_match('/_(e2e|test)$/', $database)) {
            $this->error("Refusing to write fixtures to {$database}: only the e2e and test databases.");

            return self::FAILURE;
        }
        $user = DB::table('users')->where('email', (string) $this->option('email'))->first();
        $site = $user ? DB::table('site_members')->where('user_id', $user->id)->first() : null;
        if ($site === null) {
            $this->error('No such owner.');

            return self::FAILURE;
        }
        $ctx = new SiteContext($site->site_id, $user->id);
        $photos = [];
        foreach ([[2400, 1600, 30], [1800, 1200, 140], [1600, 1600, 220], [2000, 1333, 80], [1600, 1067, 300], [1800, 1200, 190], [2400, 1000, 260]] as $i => [$w, $h, $hue]) {
            $photos[] = $media->upload($ctx, self::photo($w, $h, $hue), "photo-{$i}.jpg");
        }
        $o = new stdClass;
        $set = fn (array $style) => $style;

        // 1. A hero with its photo on the right (the design acceptance layout), and a short section.
        $hero = [
            ['id' => 'hero0001', 'type' => 'hero', 'version' => 4, 'props' => [
                'heading' => 'Gardens that grow with you', 'headingLevel' => 'h1',
                'text' => 'Garden design, planting and lawn care for homes and businesses across the valley.',
                'image' => ['assetId' => $photos[0]['id'], 'alt' => 'A walled garden with flowering borders'],
                'style' => $set(['root' => ['base' => ['direction' => 'row'], 'tablet' => ['direction' => 'column', 'align' => 'stretch'], 'mobile' => ['direction' => 'column']], 'media' => ['base' => ['height' => '500px', 'objectFit' => 'cover'], 'mobile' => ['height' => '320px']]]),
            ], 'children' => ['butn0001', 'butn0002']],
            ['id' => 'butn0001', 'type' => 'button', 'version' => 6, 'props' => ['label' => 'Get a quote', 'href' => '/contact']],
            ['id' => 'butn0002', 'type' => 'button', 'version' => 6, 'props' => ['label' => 'Our work', 'href' => '/work', 'variant' => 'secondary']],
            ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'What we do', 'element' => 'h2']],
            ['id' => 'text0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => str_repeat('We plan, plant and look after gardens of every size, from courtyards to estates. ', 6)]],
        ];
        $this->page($pages, $ctx, '/perf-hero', 'Perf hero', $hero, ['hero0001', 'text0001', 'text0002']);
        // With entrance animations: the hero (its h1 and image are the likely LCP, so it stays still) and the text below.
        $this->page($pages, $ctx, '/perf-hero-motion', 'Perf hero motion', self::animate($hero, ['hero0001' => 'load', 'text0001' => 'view', 'text0002' => 'view']), ['hero0001', 'text0001', 'text0002']);

        // 2. Image-heavy: a gallery of six photos in columns, then a full-width photo with a caption.
        $nodes = [['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Our recent work', 'element' => 'h1']]];
        $cols = [];
        foreach ([0, 1] as $row) {
            $children = [];
            foreach ([0, 1, 2] as $c) {
                $col = sprintf('col%d%d0001', $row, $c);
                $img = sprintf('img%d%d0001', $row, $c);
                $children[] = $col;
                $nodes[] = ['id' => $col, 'type' => 'column', 'version' => 6, 'props' => $o, 'children' => [$img]];
                $nodes[] = ['id' => $img, 'type' => 'image', 'version' => 5, 'props' => [
                    'image' => ['assetId' => $photos[1 + ($row * 3 + $c) % 6]['id'], 'alt' => 'Garden project '.($row * 3 + $c + 1)],
                    'caption' => 'Project '.($row * 3 + $c + 1),
                    'style' => ['media' => ['base' => ['aspectRatio' => '4/3', 'objectFit' => 'cover']]],
                ]];
            }
            $id = "cols{$row}0001";
            $cols[] = $id;
            $nodes[] = ['id' => $id, 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]]], 'children' => $children];
        }
        $nodes[] = ['id' => 'imagbig01', 'type' => 'image', 'version' => 5, 'props' => ['image' => ['assetId' => $photos[6]['id'], 'alt' => 'A meadow garden at dusk'], 'caption' => 'Meadow planting, 2026', 'style' => ['root' => ['base' => ['maxWidth' => '@container.wide']]]]];
        $this->page($pages, $ctx, '/perf-images', 'Perf images', $nodes, ['text0001', ...$cols, 'imagbig01']);
        // The heading and the first row hold the likely LCP (left still); the second row and the wide photo fade up in view.
        $this->page($pages, $ctx, '/perf-images-motion', 'Perf images motion', self::animate($nodes, ['text0001' => 'load', 'cols00001' => 'view', 'cols10001' => 'view', 'imagbig01' => 'view']), ['text0001', ...$cols, 'imagbig01']);

        // 3. Nested layout: sections with backgrounds, groups in rows, columns with nested groups (depth 7).
        $nodes = [];
        $top = [];
        foreach (range(1, 3) as $s) {
            $section = "sect{$s}0001";
            $top[] = $section;
            $nodes[] = ['id' => $section, 'type' => 'section', 'version' => 6, 'props' => ['contentWidth' => $s === 2 ? 'wide' : 'default', 'style' => ['root' => ['base' => ['backgroundColor' => $s % 2 ? '@color.surface' : '@color.background', 'paddingTop' => '@space.xl', 'paddingBottom' => '@space.xl']]]], 'children' => ["head{$s}0001", "row{$s}00001"]];
            $nodes[] = ['id' => "head{$s}0001", 'type' => 'text', 'version' => 3, 'props' => ['text' => $s === 1 ? 'A layout with depth' : "Section {$s}", 'element' => $s === 1 ? 'h1' : 'h2']];
            $nodes[] = ['id' => "row{$s}00001", 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['base' => ['columns' => '1fr 2fr 1fr', 'gap' => '@space.lg'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]]], 'children' => ["c{$s}a00001", "c{$s}b00001", "c{$s}c00001"]];
            foreach (['a', 'b', 'c'] as $k) {
                $nodes[] = ['id' => "c{$s}{$k}00001", 'type' => 'column', 'version' => 6, 'props' => $o, 'children' => ["g{$s}{$k}00001"]];
                $nodes[] = ['id' => "g{$s}{$k}00001", 'type' => 'group', 'version' => 5, 'props' => ['style' => ['root' => ['base' => ['direction' => 'row', 'wrap' => 'wrap', 'gap' => '@space.sm', 'paddingTop' => '@space.md', 'paddingLeft' => '@space.md', 'paddingRight' => '@space.md', 'paddingBottom' => '@space.md', 'borderWidth' => '1px', 'borderStyle' => 'solid', 'borderColor' => '@color.border', 'borderRadius' => '@radius.md'], 'mobile' => ['direction' => 'column']]]], 'children' => ["n{$s}{$k}00001", "b{$s}{$k}00001"]];
                $nodes[] = ['id' => "n{$s}{$k}00001", 'type' => 'group', 'version' => 5, 'props' => ['style' => ['root' => ['base' => ['gap' => '@space.xs']]]], 'children' => ["t{$s}{$k}00001", "p{$s}{$k}00001"]];
                $nodes[] = ['id' => "t{$s}{$k}00001", 'type' => 'text', 'version' => 3, 'props' => ['text' => "Card {$s}{$k}", 'element' => 'h3']];
                $nodes[] = ['id' => "p{$s}{$k}00001", 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Nested two groups deep inside a column of a Columns block in a section.', 'style' => ['root' => ['base' => ['color' => '@color.muted', 'fontSize' => '@fontSize.sm']]]]];
                $nodes[] = ['id' => "b{$s}{$k}00001", 'type' => 'button', 'version' => 6, 'props' => ['label' => 'Details', 'href' => '#', 'size' => 'small', 'variant' => 'secondary']];
            }
        }
        $this->page($pages, $ctx, '/perf-nested', 'Perf nested', $nodes, $top);
        // Every section and card animates (the first section holds the h1, so it stays still).
        $animated = ['sect10001' => 'load', 'sect20001' => 'view', 'sect30001' => 'view'];
        foreach (range(1, 3) as $s) {
            foreach (['a', 'b', 'c'] as $k) {
                $animated["g{$s}{$k}00001"] = 'view';
            }
        }
        $this->page($pages, $ctx, '/perf-nested-motion', 'Perf nested motion', self::animate($nodes, $animated), $top);

        // 4. Entrances on content that is on screen when the page opens (not the LCP): the h1 stays
        //    still (protected); an intro and three cards in view fade up; three sections further down too.
        $nodes = [
            ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Seasonal garden care', 'element' => 'h1']],
            ['id' => 'text0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Planting, pruning and lawn care through the year, planned around your garden and your time.']],
            ['id' => 'cols0001', 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['mobile' => ['columns' => '1']]]], 'children' => ['coli0001', 'coli0002', 'coli0003']],
        ];
        foreach (['Spring', 'Summer', 'Autumn'] as $i => $season) {
            $n = $i + 1;
            $nodes[] = ['id' => "coli000{$n}", 'type' => 'column', 'version' => 6, 'props' => $o, 'children' => ["card000{$n}"]];
            $nodes[] = ['id' => "card000{$n}", 'type' => 'group', 'version' => 5, 'props' => ['style' => ['root' => ['base' => ['paddingTop' => '@space.md', 'paddingBottom' => '@space.md', 'paddingLeft' => '@space.md', 'paddingRight' => '@space.md', 'backgroundColor' => '@color.surface', 'borderRadius' => '@radius.md']]]], 'children' => ["cath000{$n}", "catx000{$n}"]];
            $nodes[] = ['id' => "cath000{$n}", 'type' => 'text', 'version' => 3, 'props' => ['text' => $season, 'element' => 'h3']];
            $nodes[] = ['id' => "catx000{$n}", 'type' => 'text', 'version' => 3, 'props' => ['text' => "What we do in {$season}: planting, feeding and tidying, timed for the season."]];
        }
        $top = ['text0001', 'text0002', 'cols0001'];
        foreach (range(1, 3) as $s) {
            $nodes[] = ['id' => "more{$s}0001", 'type' => 'section', 'version' => 6, 'props' => ['style' => ['root' => ['base' => ['minHeight' => '70vh']]]], 'children' => ["mort{$s}0001", "morp{$s}0001"]];
            $nodes[] = ['id' => "mort{$s}0001", 'type' => 'text', 'version' => 3, 'props' => ['text' => "Further down {$s}", 'element' => 'h2']];
            $nodes[] = ['id' => "morp{$s}0001", 'type' => 'text', 'version' => 3, 'props' => ['text' => str_repeat('A section further down the page. ', 8)]];
            $top[] = "more{$s}0001";
        }
        $this->page($pages, $ctx, '/perf-intro-motion', 'Perf intro motion', self::animate($nodes, ['text0002' => 'view', 'card0001' => 'view', 'card0002' => 'view', 'card0003' => 'view', 'more10001' => 'view', 'more20001' => 'view', 'more30001' => 'view']), $top);

        // Shared header/footer and a native contact form: the complete-website baseline.
        $forms = app(FormService::class);
        $form = $forms->save($ctx, ['baseVersion' => 0, 'requestKey' => Uuid::v7(), 'definition' => [
            'name' => 'Performance contact', 'submitLabel' => 'Send enquiry', 'successMessage' => 'Thank you for your enquiry.',
            'fields' => [
                ['id' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true],
                ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
        ]]);
        $forms->publish($ctx, $form['id'], ['expectedVersion' => 1, 'requestKey' => Uuid::v7()]);
        $components = app(ComponentService::class);
        $menus = app(MenuService::class);
        $menu = $menus->save($ctx, ['baseVersion' => 0, 'requestKey' => Uuid::v7(), 'definition' => ['name' => 'Main navigation', 'items' => [
            ['id' => 'home', 'label' => 'Home', 'type' => 'url', 'pageId' => null, 'href' => '/perf-hero', 'anchor' => '', 'parentId' => null],
            ['id' => 'services', 'label' => 'Services', 'type' => 'url', 'pageId' => null, 'href' => '/perf-nested', 'anchor' => '', 'parentId' => null],
            ['id' => 'consulting', 'label' => 'Garden consulting', 'type' => 'url', 'pageId' => null, 'href' => '/perf-contact', 'anchor' => '', 'parentId' => 'services'],
            ['id' => 'contact', 'label' => 'Contact', 'type' => 'url', 'pageId' => null, 'href' => '/perf-contact', 'anchor' => '', 'parentId' => null],
        ]]]);
        $menus->publish($ctx, $menu['id'], ['expectedVersion' => 1, 'requestKey' => Uuid::v7()]);
        $shared = [];
        foreach (['header', 'footer'] as $slot) {
            $component = $components->create($ctx, ['name' => 'Performance '.$slot, 'nodes' => [
                ['id' => $slot.'001', 'type' => 'group', 'version' => 5, 'props' => ['element' => $slot], 'children' => $slot === 'header' ? ['label001', 'nav00001'] : ['label001']],
                ['id' => 'label001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Garden Studio — thoughtful outdoor spaces.']],
                ...($slot === 'header' ? [['id' => 'nav00001', 'type' => 'navigation', 'version' => 1, 'props' => ['menuId' => $menu['id']]]] : []),
            ]]);
            $components->publish($ctx, $component['id'], ['expectedVersion' => 1, 'idempotencyKey' => Uuid::v7()]);
            $shared[$slot] = $component['id'];
        }
        $this->page($pages, $ctx, '/perf-contact', 'Contact | Garden Studio', [
            ['id' => 'header01', 'type' => 'instance', 'version' => 2, 'props' => ['componentId' => $shared['header']]],
            ['id' => 'heading1', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Let’s plan your garden', 'element' => 'h1']],
            ['id' => 'intro001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Tell us about the outdoor space you would like to create.']],
            ['id' => 'contact1', 'type' => 'form', 'version' => 3, 'props' => ['form' => ['id' => $form['id']]]],
            ['id' => 'footer01', 'type' => 'instance', 'version' => 2, 'props' => ['componentId' => $shared['footer']]],
        ], ['header01', 'heading1', 'intro001', 'contact1', 'footer01']);

        $newsletter = $forms->save($ctx, ['baseVersion' => 0, 'requestKey' => Uuid::v7(), 'definition' => [
            'name' => 'Demo newsletter', 'submitLabel' => 'Subscribe', 'successMessage' => 'Your demo signup has been recorded.',
            'fields' => [['id' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true]],
        ]]);
        $forms->publish($ctx, $newsletter['id'], ['expectedVersion' => 1, 'requestKey' => Uuid::v7()]);
        $library = app(PatternLibrary::class);
        $demo = $library->document(array_column($library->all(), 'id'));
        $slideIndex = 0;
        foreach ($demo['nodes'] as &$node) {
            $props = Json::entries($node['props']);
            if ($node['type'] === 'navigation') {
                $props['menuId'] = $menu['id'];
            }
            if ($node['type'] === 'form') {
                $props['form'] = ['id' => $newsletter['id']];
            }
            if ($node['type'] === 'logo') {
                $props['image'] = ['assetId' => $photos[2]['id'], 'alt' => 'Arkon Studio demo logo'];
            }
            if ($node['type'] === 'slide') {
                $props['style']['root']['base']['backgroundImage'] = ['assetId' => $photos[$slideIndex++ % 2]['id']];
            }
            if ($node['type'] === 'text') {
                $style = Json::toArray($props['style'] ?? []);
                $style['root']['base']['fontFamily'] = 'inter';
                $props['style'] = $style;
            }
            if ($node['type'] === 'group' && isset($props['style']['root']['base']['backgroundGradient']) && ($props['style']['root']['base']['minHeight'] ?? '') === '22rem') {
                $props['style']['root']['base']['backgroundImage'] = ['assetId' => $photos[1]['id']];
            }
            $node['props'] = $props;
        }
        unset($node);
        $this->page($pages, $ctx, '/perf-reference-inter', 'Arkon Studio — local Inter demonstration', array_values(array_filter($demo['nodes'], fn ($node) => $node['type'] !== 'page')), $demo['nodes'][$demo['root']]['children']);
        // Compare the same design using the real pattern default: no web font download.
        foreach ($demo['nodes'] as &$node) {
            if ($node['type'] === 'text') {
                $props = Json::entries($node['props']);
                unset($props['style']['root']['base']['fontFamily']);
                if ($props['style']['root']['base'] === []) {
                    $props['style']['root']['base'] = new stdClass;
                }
                $node['props'] = $props;
            }
        }
        unset($node);
        $autoplayDemo = $demo;
        foreach ($autoplayDemo['nodes'] as &$node) {
            if ($node['type'] === 'slider') {
                $node['props']['autoplay'] = true;
                $node['props']['pauseOnHover'] = true;
            }
        }
        unset($node);
        $this->page($pages, $ctx, '/perf-reference-autoplay', 'Arkon Studio — autoplay demonstration', array_values(array_filter($autoplayDemo['nodes'], fn ($node) => $node['type'] !== 'page')), $autoplayDemo['nodes'][$autoplayDemo['root']]['children']);
        $this->page($pages, $ctx, '/perf-reference', 'Arkon Studio — builder demonstration', array_values(array_filter($demo['nodes'], fn ($node) => $node['type'] !== 'page')), $demo['nodes'][$demo['root']]['children']);
        $this->info('Published /perf-reference and /perf-contact, /perf-hero, /perf-images, /perf-nested, /perf-intro-motion and the -motion variants.');

        return self::SUCCESS;
    }

    /**
     * The same blocks with entrance animations (fade up, the trigger per block) added to their root style.
     *
     * @param  array<string, 'load'|'view'>  $triggers  node id → trigger
     */
    private static function animate(array $nodes, array $triggers): array
    {
        return array_map(function (array $node) use ($triggers) {
            if (! isset($triggers[$node['id']])) {
                return $node;
            }
            $props = is_array($node['props']) ? $node['props'] : [];
            $style = $props['style'] ?? [];
            $style['root']['base'] = [...($style['root']['base'] ?? []), 'animation' => 'fade-up', 'animationTrigger' => $triggers[$node['id']]];
            $node['props'] = [...$props, 'style' => $style];

            return $node;
        }, $nodes);
    }

    /** Creates a page with these blocks (top-level ids in order), saves and publishes it. */
    private function page(PageService $pages, SiteContext $ctx, string $path, string $title, array $nodes, array $top): void
    {
        $pageId = Uuid::v7();
        $root = 'root'.substr(str_replace('-', '', $pageId), -8);
        DB::table('pages')->insert(['id' => $pageId, 'site_id' => $ctx->siteId, 'path' => $path, 'title' => $title]);
        DB::table('page_drafts')->insert(['page_id' => $pageId, 'site_id' => $ctx->siteId, 'version' => 1, 'document' => Json::encode([
            'schemaVersion' => 1, 'root' => $root, 'nodes' => [$root => ['id' => $root, 'type' => 'page', 'version' => 6, 'props' => new stdClass, 'children' => []]], 'seo' => new stdClass,
        ])]);
        $byId = array_column($nodes, null, 'id');
        $ops = [];
        foreach ($top as $i => $id) {
            $subtree = [];
            $visit = function (string $nodeId) use (&$visit, &$subtree, $byId) {
                $subtree[] = $byId[$nodeId];
                foreach ($byId[$nodeId]['children'] ?? [] as $child) {
                    $visit($child);
                }
            };
            $visit($id);
            $ops[] = ['op' => 'insertNode', 'parentId' => $root, 'index' => $i, 'nodes' => $subtree];
        }
        $pages->saveDraft($ctx, ['pageId' => $pageId, 'baseVersion' => 1, 'saveKey' => 'perf-'.Operations::newNodeId().Operations::newNodeId(), 'operations' => Json::decode(Json::encode($ops))]);
        $pages->publish($ctx, ['pageId' => $pageId, 'expectedVersion' => 2, 'idempotencyKey' => 'perf-'.Operations::newNodeId().Operations::newNodeId()]);
    }

    /** A photo-like JPEG: soft colour fields with grain (compresses like a real photo). */
    private static function photo(int $width, int $height, int $hue): string
    {
        $small = imagecreatetruecolor(48, 32);
        mt_srand($hue);
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 48; $x++) {
                $h = ($hue + mt_rand(-25, 25) + $y) % 360;
                [$r, $g, $b] = self::hsl($h, 0.45, 0.35 + 0.3 * ($y / 32) + mt_rand(-5, 5) / 100);
                imagesetpixel($small, $x, $y, imagecolorallocate($small, $r, $g, $b));
            }
        }
        $image = imagecreatetruecolor($width, $height);
        imagecopyresampled($image, $small, 0, 0, 0, 0, $width, $height, 48, 32);
        for ($i = 0; $i < $width * $height / 40; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $c = imagecolorat($image, $x, $y);
            $d = mt_rand(-28, 28);
            imagesetpixel($image, $x, $y, imagecolorallocate($image, max(0, min(255, (($c >> 16) & 255) + $d)), max(0, min(255, (($c >> 8) & 255) + $d)), max(0, min(255, ($c & 255) + $d))));
        }
        ob_start();
        imagejpeg($image, null, 88);

        return (string) ob_get_clean();
    }

    /** @return array{int, int, int} */
    private static function hsl(float $h, float $s, float $l): array
    {
        $l = max(0, min(1, $l));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (intdiv((int) $h, 60) % 6) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
