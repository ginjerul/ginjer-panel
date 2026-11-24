<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\RelationManagers;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Throwable;

/**
 * @method Network getOwnerRecord()
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Servers';

    public function isReadOnly(): bool
    {
        return !auth()->user()?->can('update', $this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('server_id')
                    ->label('Server')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->relationship('server', 'name', fn (Builder $query, ?NetworkMember $record) => $query
                        // a server can only be in one network
                        ->whereNotIn('id', NetworkMember::query()
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->pluck('server_id')))
                    ->getOptionLabelFromRecordUsing(fn (Server $server) => "$server->name ({$server->node->name})"),
                Select::make('role')
                    ->options(MemberRole::class)
                    ->required()
                    ->live()
                    ->default(MemberRole::Shard->value),
                TextInput::make('shard_name')
                    ->label('Shard name')
                    ->helperText('Its name in velocity.toml and in the regions list.')
                    ->alphaDash()
                    ->maxLength(64)
                    ->visible(fn (Get $get) => $this->isShard($get('role')))
                    ->required(fn (Get $get) => $this->isShard($get('role')))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('network_id', $this->getOwnerRecord()->getKey())),
                Toggle::make('nodes_leader')
                    ->label('Nodes leader')
                    ->helperText('Exactly one shard. It owns all towns, nations and wars; the others follow it.')
                    ->inline(false)
                    ->visible(fn (Get $get) => $this->isShard($get('role'))),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('shard_name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('server.node'))
            ->defaultSort('role')
            ->columns([
                TextColumn::make('server.name')
                    ->label('Server')
                    ->description(fn (NetworkMember $member) => $member->server->node->name),
                TextColumn::make('role')
                    ->badge(),
                TextColumn::make('shard_name')
                    ->label('Shard name')
                    ->placeholder('-'),
                IconColumn::make('nodes_leader')
                    ->label('Leader')
                    ->boolean()
                    ->trueIcon('tabler-crown')
                    ->falseIcon('tabler-point')
                    ->trueColor('warning')
                    ->falseColor('gray'),
                TextColumn::make('state')
                    ->label('State')
                    ->state(fn (NetworkMember $member) => rescue(fn () => $member->server->retrieveStatus()->value, 'unknown', false))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'running' => 'success',
                        'starting', 'stopping' => 'warning',
                        'missing', 'unknown' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('make_leader')
                    ->label('Make leader')
                    ->tooltip('Make leader')
                    ->icon('tabler-crown')
                    ->color('warning')
                    ->visible(fn (NetworkMember $member) => $member->role === MemberRole::Shard && !$member->nodes_leader)
                    ->requiresConfirmation()
                    ->modalDescription('Marks this shard as the Nodes leader here. Push the config and restart the shards to apply it. If the current leader is down, use "Promote now" instead.')
                    ->action(fn (NetworkMember $member) => $member->update(['nodes_leader' => true])),
                Action::make('promote')
                    ->label('Promote now')
                    ->tooltip('Promote now (leader is down)')
                    ->icon('tabler-arrow-big-up-lines')
                    ->color('danger')
                    ->visible(fn (NetworkMember $member) => $member->role === MemberRole::Shard && !$member->nodes_leader)
                    ->requiresConfirmation()
                    ->modalDescription('Runs "nda shards promote" on this shard. It becomes the Nodes leader once the old leader\'s lock has expired. Use this when the leader is down. It is also marked as the leader here.')
                    ->action(function (NetworkMember $member) {
                        try {
                            \Ginjer\Network\Services\Ops\Console::run($member->server, 'nda shards promote');
                            $member->update(['nodes_leader' => true]);
                            Notification::make()->title('Promote sent to ' . $member->displayName())->body('Check Status in a few seconds. Push the config so the roles stay this way after a restart.')->success()->send();
                        } catch (Throwable $exception) {
                            Notification::make()->title('Couldn\'t send the command')->body('The shard must be running. ' . $exception->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make()->label('Remove')->tooltip('Remove from network'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add server')
                    ->createAnother(false),
            ])
            ->emptyStateHeading('No servers yet')
            ->emptyStateDescription('Add the Velocity proxy, every Reef shard and the Redis server.');
    }

    private function isShard(mixed $role): bool
    {
        return $role === MemberRole::Shard || $role === MemberRole::Shard->value;
    }
}
