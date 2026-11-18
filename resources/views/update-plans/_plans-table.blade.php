<table>
    <tr><th>計画</th><th>状態</th><th>対象</th><th>予定期間</th></tr>
    @forelse($plans as $plan)
        <tr>
            <td><a href="{{ route('update-plans.show', $plan) }}">{{ $plan->plan_code }} {{ $plan->title }}</a></td>
            <td>{{ $plan->status }}</td>
            <td>{{ $plan->assets->pluck('name')->join('、') }}</td>
            <td>{{ $plan->planned_start_at }} – {{ $plan->planned_end_at }}</td>
        </tr>
    @empty
        <tr><td colspan="4">計画はありません</td></tr>
    @endforelse
</table>
