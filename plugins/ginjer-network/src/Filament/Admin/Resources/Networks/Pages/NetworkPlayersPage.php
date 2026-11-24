<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\Players\PlayerAdmin;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who is online where, across every shard; kick, message and ban network-wide.
 *
 * @property Network $record
 */
class NetworkPlayersPage extends Page implements HasTable
{
    use Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    public string $mode = 'online'; // online, bans

    public ?string $error = null;

    protected function networkAbility(): string
    {
        return 'players';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': players';
    }

    public function getSubheading(): ?string
    {
        if ($this->mode === 'bans') {
            return 'Bans on any shard. Minecraft keeps bans per server, so a network ban is the same ban on every shard; "missing on" means the player can still join there.';
        }

        return $this->error !== null
            ? "Can't reach Redis ({$this->error}), so who is online isn't known."
            : 'Who is online on each shard, as Nodes reports it (every few seconds). Ping and position need a Nodes version that reports them.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_mode')
                ->label(fn () => $this->mode === 'online' ? 'Show bans' : 'Show online players')
                ->icon(fn () => $this->mode === 'online' ? 'tabler-ban' : 'tabler-users')
                ->color('gray')
                ->action(function () {
                    $this->mode = $this->mode === 'online' ? 'bans' : 'online';
                    $this->resetTable();
                }),
            Action::make('exclude_ban_name')
                ->label('Ban a player')
                ->icon('tabler-ban')
                ->color('danger')
                ->schema([
                    TextInput::make('name')->label('Minecraft name')->required()->rule(fn () => fn ($a, $v, $fail) => PlayerAdmin::validName((string) $v) ? null : $fail('That isn\'t a Minecraft name.')),
                    TextInput::make('reason')->maxLength(200),
                ])
                ->action(fn (array $data) => $this->ban($data['name'], (string) ($data['reason'] ?? ''))),
        ];
    }

    private function ban(string $name, string $reason): void
    {
        try {
            $result = app(PlayerAdmin::class)->ban($this->record, $name, $reason);
            NetworkLog::record($this->record, 'players', "Banned $name", $reason ?: null, 'warning');
            Notification::make()
                ->title("$name banned on " . count($result['done']) . ' shard(s)')
                ->body($result['failed'] === [] ? null : 'Not running, so not banned there yet: ' . implode(', ', $result['failed']) . '. Ban again once they\'re up.')
                ->color($result['failed'] === [] ? 'success' : 'warning')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t ban')->body($exception->getMessage())->danger()->send();
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->mode === 'bans') {
            return collect(app(PlayerAdmin::class)->bans($this->record))->keyBy(fn ($b) => strtolower($b['name']))->all();
        }
        $presence = app(NodesStatusService::class)->presence($this->record);
        $this->error = $presence['reachable'] ? null : $presence['error'];

        return collect($presence['players'])->keyBy('uuid')->all();
    }

    public function table(Table $table): Table
    {
        $online = $this->mode === 'online';

        return $table
            ->records(function (?string $search) {
                $rows = $this->rows();

                return filled($search) ? array_filter($rows, fn ($r) => Str::contains($r['name'], $search, true)) : $rows;
            })
            ->searchable()
            ->paginated(false)
            ->poll($online ? '10s' : null)
            ->columns($online ? [
                TextColumn::make('name')->label('Player')->description(fn (array $record) => $record['staff'] ? 'staff' : null),
                TextColumn::make('shard')->badge(),
                TextColumn::make('ping')->formatStateUsing(fn ($state) => $state === null ? '-' : "$state ms")
                    ->color(fn ($state) => $state === null ? null : ($state > 250 ? 'danger' : ($state > 120 ? 'warning' : null))),
                TextColumn::make('where')->label('Position')->placeholder('-'),
            ] : [
                TextColumn::make('name')->label('Player')->description(fn (array $record) => $record['reason'] ?: null),
                TextColumn::make('created')->label('Since')->formatStateUsing(fn ($state) => rescue(fn () => \Illuminate\Support\Carbon::parse($state)->diffForHumans(), $state, false)),
                TextColumn::make('source')->label('By'),
                TextColumn::make('missing')->label('Shards')
                    ->badge()
                    ->state(fn (array $record) => $record['missing'] === [] ? 'All shards' : 'Missing on ' . implode(', ', $record['missing']))
                    ->color(fn (array $record) => $record['missing'] === [] ? 'success' : 'warning'),
            ])
            ->recordActions($online ? [
                Action::make('exclude_message')
                    ->label('Message')
                    ->icon('tabler-message')
                    ->color('gray')
                    ->schema([TextInput::make('text')->label('Message')->required()->maxLength(200)])
                    ->action(function (array $record, array $data) {
                        app(PlayerAdmin::class)->message($this->record, $record['name'], $record['shard'], $data['text']);
                        Notification::make()->title("Sent to {$record['name']}")->success()->send();
                    }),
                Action::make('exclude_kick')
                    ->label('Kick')
                    ->icon('tabler-door-exit')
                    ->color('warning')
                    ->schema([TextInput::make('reason')->maxLength(200)])
                    ->action(function (array $record, array $data) {
                        app(PlayerAdmin::class)->kick($this->record, $record['name'], $record['shard'], (string) ($data['reason'] ?? ''));
                        NetworkLog::record($this->record, 'players', "Kicked {$record['name']} from {$record['shard']}", $data['reason'] ?? null);
                        Notification::make()->title("Kicked {$record['name']}")->success()->send();
                    }),
                Action::make('exclude_ban')
                    ->label('Ban')
                    ->icon('tabler-ban')
                    ->color('danger')
                    ->schema([TextInput::make('reason')->maxLength(200)])
                    ->action(fn (array $record, array $data) => $this->ban($record['name'], (string) ($data['reason'] ?? ''))),
            ] : [
                Action::make('exclude_reban')
                    ->label('Ban everywhere')
                    ->icon('tabler-ban')
                    ->color('warning')
                    ->visible(fn (array $record) => $record['missing'] !== [])
                    ->action(fn (array $record) => $this->ban($record['name'], $record['reason'])),
                Action::make('exclude_unban')
                    ->label('Unban')
                    ->icon('tabler-lock-open')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (array $record) {
                        $result = app(PlayerAdmin::class)->unban($this->record, $record['name']);
                        NetworkLog::record($this->record, 'players', "Unbanned {$record['name']}");
                        Notification::make()->title("{$record['name']} unbanned on " . count($result['done']) . ' shard(s)')->success()->send();
                        $this->resetTable();
                    }),
            ])
            ->emptyStateIcon($online ? 'tabler-users' : 'tabler-ban')
            ->emptyStateHeading($online ? 'Nobody online' : 'No bans');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
