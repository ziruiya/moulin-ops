<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetRelationship;
use App\Models\LifecycleRecord;
use App\Services\AuditLogger;

class DemoInventorySeeder
{
    public const EC_SERVICE = 'DEMO-SVC-EC-01';

    public const INTERNAL_SERVICE = 'DEMO-SVC-INTERNAL-01';

    public const SALON_SERVICE = 'DEMO-SVC-SALON-01';

    public const EC_WEB = 'DEMO-EC-WEB-01';

    public const INTERNAL_APP = 'DEMO-INTERNAL-APP-01';

    public const DATABASE = 'DEMO-DB-01';

    public function __construct(private AuditLogger $audit) {}

    /** @return array<string, Asset> */
    public function run(?int $actorId): array
    {
        $inventory = [
            self::EC_SERVICE => ['公開ECサービス', 'Service', 'Critical', true, '商品検索と模擬注文を提供する業務サービス。検証環境のランタイムも記録。'],
            self::INTERNAL_SERVICE => ['社内業務サービス', 'Service', 'High', false, '社内申請と照会を提供する業務サービス。検証環境のランタイムも記録。'],
            self::SALON_SERVICE => ['サロン支援サービス', 'Service', 'Medium', false, 'サロン向けの予約情報参照を想定した業務サービス。'],
            self::EC_WEB => ['公開EC Web', 'Server', 'Critical', true, 'ec-web.demo.invalid 上の公開EC用 Web・アプリケーション。'],
            self::INTERNAL_APP => ['社内業務アプリ', 'Application', 'High', false, 'internal-app.demo.invalid 上の社内業務アプリケーション。'],
            self::DATABASE => ['共用データベース', 'Database', 'High', false, 'db.demo.invalid 上のデモ用データベース。'],
        ];
        $assets = [];
        foreach ($inventory as $code => [$name, $type, $criticality, $exposed, $description]) {
            $asset = Asset::updateOrCreate(['asset_code' => $code], [
                'name' => $name,
                'asset_type' => $type,
                'environment' => 'Production',
                'operational_status' => 'Active',
                'criticality' => $criticality,
                'internet_exposed' => $exposed,
                'description' => 'DEMO｜'.$description,
                'inventory_source' => $code === self::SALON_SERVICE ? null : 'DEMO インベントリ台帳',
                'inventory_status' => match ($code) {
                    self::INTERNAL_APP => 'Stale',
                    self::SALON_SERVICE => 'Unverified',
                    default => 'Verified',
                },
                'last_verified_at' => match ($code) {
                    self::INTERNAL_APP => '2026-07-30 00:00:00',
                    self::SALON_SERVICE => null,
                    default => DemoSeeder::VERIFIED_AT,
                },
                'vendor' => 'DEMO 架空システムサポート',
                'support_contact' => 'support@vendor.demo.invalid',
                'vendor_ticket_ref' => 'DEMO-SUP-'.$code,
            ]);
            if ($asset->wasRecentlyCreated || $asset->wasChanged()) {
                $this->audit->log($asset, 'demo_inventory_seeded', [], $asset->only(['asset_code', 'inventory_status', 'last_verified_at']), $actorId);
            }
            $assets[$code] = $asset;
        }

        foreach ($this->components() as $code => $components) {
            foreach ($components as [$type, $product, $vendor, $version, $environment, $lifecycle]) {
                $component = $assets[$code]->components()->updateOrCreate([
                    'component_type' => $type,
                    'product_name' => $product,
                ], [
                    'vendor_name' => $vendor,
                    'version' => $version,
                    'version_source' => $code === self::INTERNAL_APP && $product === 'nginx' ? null : 'DEMO インストール台帳',
                    'environment' => $environment,
                    'support_phase' => $lifecycle['support_phase'],
                ]);
                if ($component->wasRecentlyCreated || $component->wasChanged()) {
                    $this->audit->log($component, 'demo_component_seeded', [], $component->only(['asset_id', 'product_name', 'version', 'support_phase']), $actorId);
                }
                $snapshot = $component->lifecycleRecords()->firstOrCreate(['verified_at' => DemoSeeder::VERIFIED_AT], $lifecycle + [
                    'product_name' => $product,
                    'version' => $version,
                    'lifecycle_status' => LifecycleRecord::statusForPhase($lifecycle['support_phase']),
                    'data_source_type' => 'OFFICIAL_MANUAL_SEED',
                ]);
                if ($snapshot->wasRecentlyCreated) {
                    $this->audit->log($snapshot, 'lifecycle_verified', [], $snapshot->attributesToArray(), $actorId);
                }
            }
        }

        foreach ([
            [self::EC_SERVICE, self::EC_WEB, 'Depends on'],
            [self::EC_SERVICE, self::DATABASE, 'Depends on'],
            [self::INTERNAL_SERVICE, self::INTERNAL_APP, 'Depends on'],
            [self::INTERNAL_SERVICE, self::DATABASE, 'Depends on'],
            [self::SALON_SERVICE, self::INTERNAL_APP, 'Depends on'],
            [self::SALON_SERVICE, self::DATABASE, 'Depends on'],
            [self::EC_WEB, self::DATABASE, 'Connects to'],
            [self::INTERNAL_APP, self::DATABASE, 'Connects to'],
        ] as [$source, $target, $relation]) {
            $relationship = AssetRelationship::updateOrCreate([
                'source_asset_id' => $assets[$source]->id,
                'target_asset_id' => $assets[$target]->id,
                'relationship_type' => $relation,
            ], ['source' => 'DEMO 構成図', 'verified_at' => DemoSeeder::VERIFIED_AT]);
            if ($relationship->wasRecentlyCreated || $relationship->wasChanged()) {
                $this->audit->log($relationship, 'demo_dependency_seeded', [], $relationship->only(['source_asset_id', 'target_asset_id', 'relationship_type']), $actorId);
            }
        }

        return $assets;
    }

    private function components(): array
    {
        $php = 'https://www.php.net/supported-versions.php';
        $laravel = 'https://laravel.com/framework/docs/13.x/releases#support-policy';
        $openssl = $this->dates('OpenSSL Release Strategy', 'https://openssl-library.org/policies/releasestrat/', 'Active', eol: '2030-04-08');
        $nginx = $this->dates('nginx.org', 'https://nginx.org/en/download.html', 'Unknown');
        $ubuntu24 = $this->dates('Canonical（通常のセキュリティ保守）', 'https://documentation.ubuntu.com/release-notes/24.04/#support-lifespan', 'Active', security: '2029-05-31', eol: '2029-05-31');
        $laravel12 = $this->dates('Laravel Support Policy', $laravel, 'Security Only', '2026-08-13', '2027-02-24', '2027-02-24');
        $laravel13 = $this->dates('Laravel Support Policy', $laravel, 'Active', security: '2028-03-17', eol: '2028-03-17');

        return [
            self::EC_SERVICE => [
                ['PHP', 'PHP', 'The PHP Group', '8.4.1', 'Staging', $this->dates('PHP Official', $php, 'Active', '2026-12-31', '2028-12-31', '2028-12-31')],
                ['Framework', 'Laravel', 'Laravel', '13.0.0', 'Staging', $laravel13],
            ],
            self::INTERNAL_SERVICE => [
                ['PHP', 'PHP', 'The PHP Group', '8.3.1', 'Staging', $this->dates('PHP Official', $php, 'Security Only', '2025-12-31', '2027-12-31', '2027-12-31')],
                ['Framework', 'Laravel', 'Laravel', '12.0.0', 'Staging', $laravel12],
            ],
            self::SALON_SERVICE => [
                ['PHP', 'PHP', 'The PHP Group', '8.5.2', 'Production', $this->dates('PHP Official', $php, 'Active', '2027-12-31', '2029-12-31', '2029-12-31')],
                ['Framework', 'Laravel', 'Laravel', '13.0.0', 'Production', $laravel13],
            ],
            self::EC_WEB => [
                ['PHP', 'PHP', 'The PHP Group', '8.1.34', 'Production', $this->dates('PHP Official', 'https://www.php.net/eol.php', 'EOL', '2024-12-31', '2025-12-31', '2025-12-31')],
                ['OS', 'Ubuntu', 'Canonical', '22.04', 'Production', $this->dates('Canonical', 'https://documentation.ubuntu.com/release-notes/22.04/#support-lifespan', 'Unknown')],
                ['Web Server', 'nginx', 'NGINX', '1.26.3', 'Production', $nginx],
                ['Framework', 'Laravel', 'Laravel', '10.48.0', 'Production', $this->dates('Laravel Support Policy', $laravel, 'EOL', '2024-08-06', '2025-02-04', '2025-02-04')],
                ['Library', 'OpenSSL', 'OpenSSL', '3.5.4', 'Production', $openssl],
            ],
            self::INTERNAL_APP => [
                ['PHP', 'PHP', 'The PHP Group', '8.2.30', 'Production', $this->dates('PHP Official', $php, 'Security Only', '2024-12-31', '2026-12-31', '2026-12-31')],
                ['OS', 'Ubuntu', 'Canonical', '24.04', 'Production', $ubuntu24],
                ['Web Server', 'nginx', 'NGINX', '1.28.0', 'Production', $nginx],
                ['Framework', 'Laravel', 'Laravel', '12.0.0', 'Production', $laravel12],
                ['Library', 'OpenSSL', 'OpenSSL', '3.5.0', 'Production', $openssl],
            ],
            self::DATABASE => [
                ['OS', 'Ubuntu', 'Canonical', '24.04', 'Production', $ubuntu24],
                ['DB', 'MariaDB', 'MariaDB Foundation', '10.11.10', 'Production', $this->dates('MariaDB Foundation（Community）', 'https://mariadb.org/about/#maintenance-policy', 'Active', '2028-02-16', eol: '2028-02-16')],
            ],
        ];
    }

    private function dates(string $name, string $url, string $phase, ?string $active = null, ?string $security = null, ?string $eol = null): array
    {
        return [
            'source_name' => $name,
            'source_url' => $url,
            'support_phase' => $phase,
            'active_support_end' => $active,
            'security_support_end' => $security,
            'eol_date' => $eol,
            'date_precision' => $eol ? 'DAY' : 'UNKNOWN',
        ];
    }
}
