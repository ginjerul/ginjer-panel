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
                    ->sortable(),
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
            ->recordActions([
                EditAction::make(),
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
        ];
    }
}
