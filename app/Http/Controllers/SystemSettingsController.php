<?php

namespace App\Http\Controllers;

use App\Models\LifecycleRecord;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    private const RULE_LABELS = [
        'data_review_days' => 'データの再確認周期',
        'eol_warning_days' => 'サポート終了間近の表示日数',
        'restore_review_days' => 'バックアップ・復元の再検証周期',
    ];

    public function index(SettingsService $settings): View
    {
        Gate::authorize('manage-system-settings');

        $sourceTypes = LifecycleRecord::query()->distinct()->orderBy('data_source_type')->pluck('data_source_type');
        $sourceUrls = LifecycleRecord::query()
            ->whereIn('data_source_type', ['OFFICIAL_MANUAL', 'OFFICIAL_MANUAL_SEED'])
            ->distinct()->orderBy('source_url')->pluck('source_url')
            ->filter(fn (string $url): bool => Str::isUrl($url, ['http', 'https']) && parse_url($url, PHP_URL_USER) === null)
            ->values();

        return view('settings.system.index', [
            'ruleValues' => $settings->rules(),
            'ruleLabels' => self::RULE_LABELS,
            'minDays' => SettingsService::MIN_DAYS,
            'maxDays' => SettingsService::MAX_DAYS,
            'sourceTypes' => $sourceTypes,
            'sourceUrls' => $sourceUrls,
            'systemConfig' => [
                'アプリケーション名' => config('app.name'),
                '実行環境' => app()->environment(),
                'タイムゾーン' => config('app.timezone'),
                'PHP バージョン' => PHP_VERSION,
                'Laravel バージョン' => app()->version(),
                'DB ドライバー' => config('database.connections.'.config('database.default').'.driver'),
                'デモデータの表示' => '表示中（DEMO DATA）',
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings): RedirectResponse
    {
        Gate::authorize('manage-system-settings');

        $fields = array_keys(SettingsService::DEFAULTS);
        $input = array_map(
            fn (mixed $value): mixed => is_string($value) && ctype_digit($value) ? (int) $value : $value,
            $request->only($fields),
        );
        $rules = Validator::make($input, array_fill_keys($fields, [
            'bail', 'required', 'integer:strict', 'between:'.SettingsService::MIN_DAYS.','.SettingsService::MAX_DAYS,
        ]), [
            'required' => ':attributeを入力してください。',
            'integer' => ':attributeには整数を入力してください。',
            'between' => ':attributeは:min〜:max日の範囲で入力してください。',
        ], self::RULE_LABELS)->validate();

        $settings->save($rules, $request->user()->id);

        return redirect()->route('settings.system.index')->with('status', 'システム設定を保存しました。');
    }
}
