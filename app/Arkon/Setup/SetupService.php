<?php

namespace App\Arkon\Setup;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\Factories;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Permissions;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * First-run setup from the server's command line. A "first visitor becomes
 * owner" web screen is racy and lets whoever reaches a fresh server first take
 * it over, so accounts are created here and nowhere else.
 */
class SetupService
{
    public function __construct(private readonly Transactions $transactions, private readonly AuditLog $audit) {}

    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** @return array{userId: string} */
    public function createMember(string $email, string $name, string $password, string $siteId, string $role): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Invalid email address');
        }
        if (strlen($password) < 12) {
            throw new ValidationException('Password must be at least 12 characters');
        }
        if (! in_array($role, Permissions::ROLES, true)) {
            throw new ValidationException("Unknown role {$role}");
        }
        if (! Uuid::isValid($siteId) || ! DB::table('sites')->where('id', $siteId)->exists()) {
            throw new ValidationException('Unknown site');
        }

        return $this->transactions->run(function () use ($email, $name, $password, $siteId, $role) {
            if (User::where('email', $email)->exists()) {
                throw new ConflictException("A user with email {$email} already exists");
            }
            $user = User::create([
                'email' => $email,
                'name' => trim($name) !== '' ? trim($name) : $email,
                'password' => $password, // hashed by the model cast
                'email_verified_at' => now(),
            ]);
            DB::table('site_members')->insert(['site_id' => $siteId, 'user_id' => $user->id, 'role' => $role]);
            $this->audit->record($siteId, null, 'cli', "user.{$role}.create", 'user', $user->id, ['email' => $email]);

            return ['userId' => $user->id];
        });
    }

    /**
     * The demo site with an unpublished home page. Idempotent per first hostname.
     *
     * @param  list<string>  $hosts
     * @return array{siteId: string, created: bool}
     */
    public function seedDemoSite(array $hosts, string $name = 'Arkon Demo'): array
    {
        $hosts = array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), $hosts)));
        if ($hosts === []) {
            throw new ValidationException('At least one hostname is required');
        }
        $existing = DB::table('site_domains')->where('hostname', $hosts[0])->value('site_id');
        if ($existing !== null) {
            return ['siteId' => $existing, 'created' => false];
        }

        return $this->transactions->run(function () use ($hosts, $name) {
            $siteId = Uuid::v7();
            DB::table('sites')->insert(['id' => $siteId, 'name' => $name, 'settings' => Json::encode(['lang' => 'en'])]);
            DB::table('site_domains')->insert(array_map(fn ($hostname) => ['hostname' => $hostname, 'site_id' => $siteId], $hosts));

            $pageId = Uuid::v7();
            $revisionId = Uuid::v7();
            $document = Factories::pageDocument([Factories::heroNode([
                'heading' => 'Build visually. Describe the rest.',
                'text' => 'Arkon is an AI-native CMS. Edit this hero, save a draft, preview it, then publish.',
            ])]);
            DB::table('pages')->insert(['id' => $pageId, 'site_id' => $siteId, 'path' => '/', 'title' => 'Home']);
            DB::table('page_revisions')->insert([
                'id' => $revisionId, 'site_id' => $siteId, 'page_id' => $pageId, 'number' => 1,
                'document' => Json::encode($document), 'title' => 'Home', 'path' => '/',
                'schema_version' => $document['schemaVersion'], 'source' => 'system', 'message' => 'Initial draft',
            ]);
            DB::table('page_drafts')->insert([
                'page_id' => $pageId, 'site_id' => $siteId, 'document' => Json::encode($document), 'version' => 1,
                'checkpoint_revision_id' => $revisionId, 'checkpoint_version' => 1,
            ]);
            $this->audit->record($siteId, null, 'system', 'site.seed', 'site', $siteId);

            return ['siteId' => $siteId, 'created' => true];
        });
    }
}
