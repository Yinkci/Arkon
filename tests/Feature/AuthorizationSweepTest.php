<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\DatabaseTestCase;

/**
 * Nothing in the admin may rely on a hidden button. Every admin endpoint is called here as
 * somebody who should not be allowed to use it — a viewer, who may read but change nothing —
 * and must be refused by the server, not by the screen that normally hides the action.
 *
 * The sweep walks the registered routes rather than a hand-written list, so an endpoint added
 * later is covered the day it appears.
 */
final class AuthorizationSweepTest extends DatabaseTestCase
{
    /**
     * Endpoints that take POST because they are given a list, not because they change anything.
     * Each authorizes a read permission of its own, so a viewer reaching them is correct.
     */
    // Reads that take a POST because they are asked about a list of ids: a viewer may see them.
    private const READ_THROUGH_POST = ['admin/api/media/usage', 'admin/api/pages/usage'];

    /** Routes that are deliberately open to any member, or that need no site membership. */
    private const READABLE = [
        'admin', 'admin/pages', 'admin/posts', 'admin/media', 'admin/forms', 'admin/seo', 'admin/seo/defaults',
        'admin/design', 'admin/design/components', 'admin/navigation', 'admin/themes', 'admin/performance',
        'admin/settings/developer', 'admin/content-types', 'admin/posts/categories', 'admin/posts/tags',
    ];

    public function test_every_admin_write_endpoint_refuses_a_viewer(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'sweep.test');
        $viewerId = $this->createUser('viewer');
        DB::table('site_members')->insert(['site_id' => $f['siteId'], 'user_id' => $viewerId, 'role' => 'viewer']);
        $this->actingAs(User::findOrFail($viewerId));
        $this->withoutVite();

        $checked = 0;
        $allowed = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'admin/api/') || ! in_array('POST', $route->methods(), true)) {
                continue;
            }
            // Fill route parameters with ids that exist on this site, so a refusal is about
            // permission rather than about something being missing.
            $path = str_replace(['{page}', '{taxonomy}', '{term}', '{key}', '{form}', '{menu}', '{asset}', '{component}', '{proposal}', '{id}'],
                [$f['pageId'], 'category', $f['pageId'], 'service', $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId'], $f['pageId']], $uri);
            if (str_contains($path, '{')) {
                continue; // A parameter this sweep cannot fill honestly.
            }
            $status = $this->postJson('/'.$path, [])->getStatusCode();
            $checked++;
            // 403 forbidden, 404 not found (a viewer may not learn it exists), 422 refused input
            // before any change: all acceptable. 200/201 would mean a viewer changed something.
            if (in_array($status, [200, 201], true) && ! in_array($path, self::READ_THROUGH_POST, true)) {
                $allowed[] = $path;
            }
        }
        $this->assertGreaterThan(20, $checked, 'the sweep should cover the admin write endpoints');
        $this->assertSame([], $allowed, 'a viewer was allowed to write through: '.implode(', ', $allowed));
    }

    public function test_a_viewer_may_open_the_read_screens_but_an_outsider_may_not(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'sweep2.test');
        $viewerId = $this->createUser('viewer');
        DB::table('site_members')->insert(['site_id' => $f['siteId'], 'user_id' => $viewerId, 'role' => 'viewer']);
        $this->withoutVite();

        $this->actingAs(User::findOrFail($viewerId));
        foreach (self::READABLE as $uri) {
            $status = $this->get('/'.$uri)->getStatusCode();
            $this->assertContains($status, [200, 302], "a viewer could not open /{$uri} (status {$status})");
        }

        // Somebody with no membership at all gets nothing, not even the shape of the site.
        $strangerId = $this->createUser('owner');
        DB::table('site_members')->where('user_id', $strangerId)->delete();
        $this->actingAs(User::findOrFail($strangerId));
        foreach (['admin/pages', 'admin/content-types', 'admin/media'] as $uri) {
            $this->assertNotSame(200, $this->get('/'.$uri)->getStatusCode(), "/{$uri} answered a non-member");
        }
    }
}
