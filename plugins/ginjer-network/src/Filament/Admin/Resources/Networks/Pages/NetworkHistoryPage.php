<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use App\Services\Servers\BuildModificationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Filament\Admin\Widgets\CpuChart;
use Ginjer\Network\Filament\Admin\Widgets\MemoryChart;
use Ginjer\Network\Filament\Admin\Widgets\PlayersChart;
use Ginjer\Network\Filament\Admin\Widgets\TickChart;
use Ginjer\Network\Filament\Admin\Widgets\TpsChart;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Models\ShardMetric;
use Ginjer\Network\Services\Health\MemoryAdvice;
use Ginjer\Network\Services\Split\SplitPlanner;
use Throwable;

/**
 * A week of each shard's TPS, tick time, players, memory and CPU (from the health check), and
 * advice for shards that run out of memory.
 *
 * @property Network $record
 */
class NetworkHistoryPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->name . ': history';
    }

    public function getSubheading(): ?string
    {
        $count = ShardMetric::where('network_id', $this->record->id)->count();

        return $count === 0
            ? 'Nothing recorded yet. The health check records every shard once a minute (it runs on Laravel\'s scheduler).'
            : 'Recorded once a minute by the health check, kept for ' . ShardMetric::KEEP_DAYS . ' days. Patterns to look for: lag at the same time every day (player peaks), after wars start, or slowly building until a restart (memory).';
    }

    /** @return list<array{member: NetworkMember, suggested: int}> */
    public function advice(): array
    {
        $out = [];
        foreach ($this->record->loadMissing('members.server')->membersWithRole(MemberRole::Shard) as $member) {
            $samples = ShardMetric::where('network_id', $this->record->id)->where('shard', $member->shard_name)
                ->where('recorded_at', '>=', now()->subHour())->get(['memory_mb', 'memory_limit_mb', 'tps', 'mspt'])->toArray();
            $suggested = MemoryAdvice::suggest($samples, (int) $member->server->memory);
            if ($suggested !== null) {
                $out[] = ['member' => $member, 'suggested' => $suggested];
            }
        }

        return $out;
    }

    public function content(Schema $schema): Schema
    {
        $callouts = [];
        foreach ($this->advice() as ['member' => $member, 'suggested' => $suggested]) {
            $server = $member->server;
            $free = app(SplitPlanner::class)->freeMemory($server->node);
            $extra = $suggested - $server->memory;
            $callouts[] = Callout::make("{$member->shard_name} needs more memory")
                ->warning()
                ->description(sprintf(
                    'For most of the last hour it ran at its memory limit while lagging, which means Java spends its time collecting garbage instead of running the game. It has %s GB; %s GB should help.%s',
                    round($server->memory / 1024, 1), round($suggested / 1024, 1),
                    $free < $extra ? " Its node {$server->node->name} only has " . round(max(0, $free) / 1024, 1) . ' GB free, so free some up first.' : ' The new limit applies after a restart.'
                ))
                ->actions([
                    Action::make("exclude_memory_{$member->id}")
                        ->label('Give it ' . round($suggested / 1024, 1) . ' GB')
                        ->disabled($free < $extra)
                        ->requiresConfirmation()
                        ->action(function () use ($server, $suggested) {
                            try {
                                app(BuildModificationService::class)->handle($server, [
                                    'memory' => $suggested,
                                    'database_limit' => $server->database_limit,
                                    'allocation_limit' => $server->allocation_limit,
                                    'backup_limit' => $server->backup_limit,
                                ]);
                                Notification::make()->title("{$server->name} now has " . round($suggested / 1024, 1) . ' GB')->body('Restart it to use the new limit.')->success()->send();
                            } catch (Throwable $exception) {
                                Notification::make()->title('Couldn\'t change the memory')->body($exception->getMessage())->danger()->send();
                            }
                        }),
                ]);
        }

        return $schema->components($callouts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    protected function getFooterWidgets(): array
    {
        $data = ['record' => $this->record];

        return [
            TpsChart::make($data),
            TickChart::make($data),
            PlayersChart::make($data),
            MemoryChart::make($data),
            CpuChart::make($data),
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
