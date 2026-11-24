<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
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
use Ginjer\Network\Jobs\RestoreNetworkBackup;
use Ginjer\Network\Jobs\TakeNetworkBackup;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkBackup;
use Ginjer\Network\Services\Ops\NetworkBackups;
use Illuminate\Database\Eloquent\Builder;

/**
 * Network backups: every server backed up at the same moment, restored together.
 *
 * @property Network $record
 */
class NetworkBackupsPage extends Page implements HasTable
{
    use \Ginjer\Network\Filament\Admin\Resources\Networks\Pages\Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    protected function networkAbility(): string
    {
        return 'control';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
        NetworkBackup::where('network_id', $this->record->id)->where('status', 'running')->get()->each->refreshStatus();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': backups';
    }

    public function getSubheading(): ?string
    {
        $schedule = $this->record->backup_every_hours ? "Automatic every {$this->record->backup_every_hours} hour(s)" : 'Automatic backups are off (set them in the network\'s Backups settings)';

        return "$schedule; the last {$this->record->backup_keep} are kept. Each one is a Pelican backup of every server in the network, taken together after saving Nodes, the worlds and Redis.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_backup_now')
                ->label('Back up now')
                ->icon('tabler-database-export')
                ->requiresConfirmation()
                ->modalDescription('Saves Nodes and the worlds on every shard, snapshots Redis, then backs up every server. Players stay online.')
                ->action(function () {
                    TakeNetworkBackup::dispatch($this->record->id, (int) auth()->id());
                    Notification::make()->title('Backup started')->body('It shows below; the result goes to your notifications.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => NetworkBackup::query()->where('network_id', $this->record->id)->latest('id'))
            ->poll('10s')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (NetworkBackup $b) => $b->created_at->diffForHumans()),
                TextColumn::make('servers')
                    ->label('Servers')
                    ->state(fn (NetworkBackup $b) => count($b->backups)),
                TextColumn::make('size')
                    ->label('Size')
                    ->state(fn (NetworkBackup $b) => \Illuminate\Support\Number::fileSize($b->serverBackups()->sum('bytes'), precision: 1)),
                TextColumn::make('status')
                    ->badge()
                    ->state(function (NetworkBackup $b) {
                        $b->refreshStatus();

                        return $b->status;
                    })
                    ->formatStateUsing(fn (string $state) => ['running' => 'In progress', 'done' => 'Ready', 'failed' => 'Failed'][$state] ?? $state)
                    ->color(fn (string $state) => ['running' => 'warning', 'done' => 'success', 'failed' => 'danger'][$state] ?? 'gray')
                    ->tooltip(fn (NetworkBackup $b) => $b->note),
            ])
            ->recordActions([
                Action::make('exclude_restore')
                    ->label('Restore')
                    ->icon('tabler-restore')
                    ->color('danger')
                    ->visible(fn (NetworkBackup $b) => $b->status === 'done' && $this->userCan('update'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (NetworkBackup $b) => "Restore every server to \"{$b->name}\"?")
                    ->modalDescription('Stops every server in the network and puts each back exactly as it was then. Everything since that backup is lost: builds, towns, wars, inventories. Start the network again when they\'re done.')
                    ->modalSubmitActionLabel('Restore everything')
                    ->action(function (NetworkBackup $b) {
                        RestoreNetworkBackup::dispatch($b->id, (int) auth()->id());
                        Notification::make()->title('Restore started')->body('The servers stop, then restore. The result goes to your notifications.')->warning()->send();
                    }),
                Action::make('exclude_delete')
                    ->visible(fn () => $this->userCan('update'))
                    ->label('Delete')
                    ->icon('tabler-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Deletes this network backup and every server backup in it.')
                    ->action(fn (NetworkBackup $b) => app(NetworkBackups::class)->remove($b)),
            ])
            ->emptyStateIcon('tabler-database')
            ->emptyStateHeading('No network backups yet');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
