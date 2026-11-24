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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Jobs\PostToDiscord;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who did what to the network, and what the panel noticed, newest first.
 *
 * @property Network $record
 */
class NetworkActivityPage extends Page implements HasTable
{
    use \Ginjer\Network\Filament\Admin\Resources\Networks\Pages\Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': activity';
    }

    public function getSubheading(): ?string
    {
        return 'Restarts, updates, config changes, backups and alerts from the last ' . NetworkEvent::KEEP_DAYS . ' days. "Panel" is the panel itself: health checks and schedules.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_discord_test')
                ->label('Test Discord')
                ->icon('tabler-brand-discord')
                ->color('gray')
                ->visible(fn () => filled($this->record->discord_webhook))
                ->action(function () {
                    try {
                        (new PostToDiscord($this->record->id, 'Test from the Ginjer Panel', 'Alerts for this network will show up here.', 'info', auth()->user()?->username))->handle();
                        Notification::make()->title('Sent to Discord')->success()->send();
                    } catch (\Throwable $exception) {
                        Notification::make()->title('Discord refused it')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $levels = ['info' => 'gray', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger'];

        return $table
            ->query(fn (): Builder => NetworkEvent::query()->with('user')->where('network_id', $this->record->id)->latest('id'))
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip()
                    ->width('1%'),
                TextColumn::make('title')
                    ->label('What')
                    ->wrap()
                    ->color(fn (NetworkEvent $e) => $levels[$e->level] === 'gray' ? null : $levels[$e->level])
                    ->description(fn (NetworkEvent $e) => $e->body ? mb_strimwidth($e->body, 0, 300, '…') : null),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => explode('.', $state)[0]),
                TextColumn::make('user.username')
                    ->label('Who')
                    ->default('Panel'),
            ])
            ->filters([
                SelectFilter::make('level')->options(['danger' => 'Problems', 'warning' => 'Warnings', 'success' => 'Recoveries', 'info' => 'Actions']),
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options(fn () => NetworkEvent::where('network_id', $this->record->id)->distinct()->pluck('kind')
                        ->mapWithKeys(fn ($k) => [explode('.', $k)[0] => explode('.', $k)[0]])->sort()->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where(fn ($q) => $q->where('kind', $data['value'])->orWhere('kind', 'like', $data['value'] . '.%')) : $query),
            ])
            ->emptyStateIcon('tabler-list-details')
            ->emptyStateHeading('Nothing yet');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
