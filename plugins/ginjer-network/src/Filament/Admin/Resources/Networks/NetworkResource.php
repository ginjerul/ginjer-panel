<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\CreateNetwork;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkBackupsPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkActivityPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkConsolePage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkPlayersPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkPluginUpdatesPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkRebalancePage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkConfigSyncPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkDatabasePage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkHistoryPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\NetworkPluginsPage;
use Ginjer\Network\Filament\Admin\Resources\Networks\RelationManagers\MembersRelationManager;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\RegionList;

class NetworkResource extends Resource
{
    protected static ?string $model = Network::class;

    protected static ?string $slug = 'networks';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-topology-star-3';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'Networks';
    }

    public static function getModelLabel(): string
    {
        return 'Network';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Networks';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ginjer';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Network $network) => collect([$network->maintenance ? 'maintenance' : null, $network->test_of_network_id ? 'test copy' : null])->filter()->implode(', ') ?: null),
                TextColumn::make('world'),
                TextColumn::make('shards')
                    ->label('Shards')
                    ->state(fn (Network $network) => $network->membersWithRole(MemberRole::Shard)->count()),
                TextColumn::make('leader')
                    ->label('Nodes leader')
                    ->state(fn (Network $network) => $network->leader()?->displayName())
                    ->placeholder('none set'),
                TextColumn::make('members_count')
                    ->label('Servers')
                    ->counts('members'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('members.server'))
            ->recordUrl(fn (Network $network) => auth()->user()?->can('view', $network) ? static::getUrl('edit', ['record' => $network]) : null)
            ->recordActions([
                EditAction::make()->visible(fn (Network $network) => auth()->user()?->can('view', $network)),
                DeleteAction::make(),
            ])
            ->emptyStateIcon('tabler-topology-star-3')
            ->emptyStateHeading('No networks yet')
            ->emptyStateDescription('A network groups a Velocity proxy, Reef shards and Redis so they start, stop and get configured together.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Network')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true),
                        TextInput::make('world')
                            ->label('Sharded world')
                            ->helperText('The world Reef splits between the shards. Others stay on one shard.')
                            ->required()
                            ->default('world'),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),
                Section::make('Reef sharding')
                    ->description('Written into the "sharding" section of reef.yml on every shard. Only the shard name differs between shards.')
                    ->columns(3)
                    ->schema([
                        Textarea::make('regions')
                            ->label('Regions')
                            ->columnSpanFull()
                            ->rows(8)
                            ->extraInputAttributes(['style' => 'font-family: monospace'])
                            ->placeholder("- {server: west, min-x: -20000, min-z: -10000, max-x: 0, max-z: 10000}\n- {server: east, min-x: 0, min-z: -10000, max-x: 20000, max-z: 10000}")
                            ->helperText('Same format as reef.yml: rectangles, polygons (points) or circles. The GinjerMap editor can draw them and copy this list. "server" is the shard name you give each shard below.')
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                    foreach (RegionList::problems((string) $value) as $problem) {
                                        $fail($problem);
                                    }
                                },
                            ]),
                        TextInput::make('shared_player_data_folder')
                            ->label('Shared player data folder')
                            ->helperText('Path inside the shard containers. Use a Pelican mount so every shard sees the same folder.')
                            ->required()
                            ->default('/reef-shared/playerdata')
                            ->columnSpan(2),
                        TextInput::make('border_protection')
                            ->label('Border protection (blocks)')
                            ->numeric()->integer()->minValue(0)
                            ->required()
                            ->default(16),
                        TextInput::make('handoff_timeout_seconds')
                            ->label('Handoff timeout (seconds)')
                            ->numeric()->integer()->minValue(1)
                            ->required()
                            ->default(5),
                    ]),
                Section::make('Nodes and Redis')
                    ->description('Written into the "sharding" section of plugins/nodes/config.yml on every shard. The panel also reads the Nodes leader and shard status from this Redis.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('redis_host')
                            ->label('Redis host')
                            ->helperText('Must be reachable from the shards and from the panel.')
                            ->required()
                            ->default('127.0.0.1'),
                        TextInput::make('redis_port')
                            ->label('Redis port')
                            ->numeric()->integer()->minValue(1)->maxValue(65535)
                            ->required()
                            ->default(6379),
                        TextInput::make('redis_password')
                            ->label('Redis password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Leave empty to keep the current one.'),
                        TextInput::make('redis_database')
                            ->label('Redis database')
                            ->numeric()->integer()->minValue(0)
                            ->required()
                            ->default(0),
                        TextInput::make('key_prefix')
                            ->label('Key prefix')
                            ->helperText('Change only if two Nodes networks share one Redis.')
                            ->required()
                            ->alphaDash()
                            ->default('nodes'),
                    ]),
                Section::make('Health checks and restarts')
                    ->description('Checked every minute. Alerts go to every root admin\'s notifications.')
                    ->columns(5)
                    ->collapsible()
                    ->schema([
                        \Filament\Forms\Components\Toggle::make('health_alerts')
                            ->label('Send alerts')
                            ->helperText('When a shard goes down, stops syncing with Nodes, or lags.')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('lag_tps')
                            ->label('Lagging below (TPS)')
                            ->numeric()->integer()->minValue(1)->maxValue(20)
                            ->default(15)
                            ->required(),
                        TextInput::make('lag_minutes')
                            ->label('For (minutes)')
                            ->numeric()->integer()->minValue(1)
                            ->default(5)
                            ->required(),
                        TextInput::make('restart_daily_at')
                            ->label('Daily rolling restart at')
                            ->placeholder('Off')
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                            ->helperText(fn () => 'HH:MM, ' . config('app.timezone') . ' time. Players are warned 10, 5 and 1 minutes before; shards restart one at a time.'),
                        \Filament\Forms\Components\Toggle::make('restart_on_lag')
                            ->label('Restart lagging shards')
                            ->helperText('Off by default: a restart disconnects that shard\'s players.')
                            ->inline(false),
                    ]),
                Section::make('Discord')
                    ->description('Posts to a Discord channel through a webhook (channel settings → Integrations → Webhooks).')
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(fn (?Network $record) => blank($record?->discord_webhook))
                    ->schema([
                        TextInput::make('discord_webhook')
                            ->label('Webhook link')
                            ->password()->revealable()
                            ->placeholder('https://discord.com/api/webhooks/...')
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && !\Ginjer\Network\Services\Activity\NetworkLog::validWebhook($value)) {
                                    $fail('That isn\'t a Discord webhook link.');
                                }
                            })
                            ->columnSpan(2)
                            ->helperText('Stored encrypted.'),
                        \Filament\Forms\Components\Select::make('discord_level')
                            ->label('Post')
                            ->options([
                                'alerts' => 'Alerts: shards down, lag, crashes, failures',
                                'all' => 'Everything in the activity log',
                                'off' => 'Nothing',
                            ])
                            ->default('alerts')
                            ->selectablePlaceholder(false),
                    ]),
                Section::make('Rolling restarts')
                    ->description('How updates, rolling restarts and the daily restart go through the shards.')
                    ->columns(4)
                    ->collapsible()
                    ->schema([
                        \Filament\Forms\Components\Select::make('rolling_order')
                            ->label('Order')
                            ->options(\Ginjer\Network\Services\Ops\RollingPlan::ORDERS)
                            ->default('followers_first')
                            ->selectablePlaceholder(false)
                            ->helperText('The leader always restarts on its own.'),
                        TextInput::make('rolling_batch')
                            ->label('Shards at a time')
                            ->numeric()->integer()->minValue(1)
                            ->default(1)->required()
                            ->helperText('More is faster, but more players are moved at once.'),
                        TextInput::make('rolling_warn_seconds')
                            ->label('Warn players first')
                            ->numeric()->integer()->minValue(0)->maxValue(300)
                            ->suffix('seconds')
                            ->default(0)->required()
                            ->helperText('A chat message on each shard before it restarts. 0: no warning.'),
                        TextInput::make('restart_settle_seconds')
                            ->label('Wait after each batch')
                            ->numeric()->integer()->minValue(0)
                            ->suffix('seconds')
                            ->default(20)->required(),
                        TextInput::make('rolling_stop_timeout')
                            ->label('Stop timeout')
                            ->numeric()->integer()->minValue(10)
                            ->suffix('seconds')
                            ->default(180)->required()
                            ->helperText('Then it\'s killed.'),
                        TextInput::make('rolling_start_timeout')
                            ->label('Start timeout')
                            ->numeric()->integer()->minValue(10)
                            ->suffix('seconds')
                            ->default(300)->required()
                            ->helperText('How long a shard may take to come back.'),
                        \Filament\Forms\Components\Select::make('rolling_on_failure')
                            ->label('When a shard fails')
                            ->options(['stop' => 'Stop (leave the rest untouched)', 'continue' => 'Carry on with the rest'])
                            ->default('stop')
                            ->selectablePlaceholder(false),
                        \Filament\Forms\Components\Toggle::make('rolling_include_proxy')
                            ->label('Restart the proxy too')
                            ->helperText('Last, on its own. It disconnects everyone, so it\'s off by default.')
                            ->inline(false),
                    ]),
                Section::make('Backups')
                    ->description('Backs up every server in the network at the same moment, after saving Nodes, the worlds and Redis, so a restore never mixes times.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('backup_every_hours')
                            ->label('Back up every (hours)')
                            ->numeric()->integer()->minValue(1)
                            ->placeholder('Only when you click Back up')
                            ->helperText('Leave empty for manual backups only.'),
                        TextInput::make('backup_keep')
                            ->label('Keep the last')
                            ->numeric()->integer()->minValue(1)
                            ->suffix('network backups')
                            ->default(5)
                            ->required()
                            ->helperText('Older ones are deleted, including their server backups.'),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworks::route('/'),
            'create' => CreateNetwork::route('/create'),
            'edit' => EditNetwork::route('/{record}/edit'),
            'plugins' => NetworkPluginsPage::route('/{record}/plugins'),
            'backups' => NetworkBackupsPage::route('/{record}/backups'),
            'history' => NetworkHistoryPage::route('/{record}/history'),
            'config-sync' => NetworkConfigSyncPage::route('/{record}/config-sync'),
            'activity' => NetworkActivityPage::route('/{record}/activity'),
            'console' => NetworkConsolePage::route('/{record}/console'),
            'players' => NetworkPlayersPage::route('/{record}/players'),
            'plugin-updates' => NetworkPluginUpdatesPage::route('/{record}/plugin-updates'),
            'rebalance' => NetworkRebalancePage::route('/{record}/rebalance'),
            'database' => NetworkDatabasePage::route('/{record}/database'),
        ];
    }
}
