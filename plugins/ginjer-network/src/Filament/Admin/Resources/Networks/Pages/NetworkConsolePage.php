<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\NetworkConsole;
use Illuminate\Support\HtmlString;

/**
 * Every shard's console in one place, and commands to several shards at once.
 *
 * @property Network $record
 */
class NetworkConsolePage extends Page
{
    use Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    /** @var list<string>|null labels shown, null for all */
    public ?array $only = null;

    public bool $live = true;

    protected function networkAbility(): string
    {
        return 'console';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': console';
    }

    public function getSubheading(): ?string
    {
        return 'The newest lines of every shard\'s and the proxy\'s log, merged by time' . ($this->live ? ', refreshed every 5 seconds' : '') . '.';
    }

    protected function getHeaderActions(): array
    {
        $options = fn () => array_combine(array_keys(NetworkConsole::targets($this->record)), array_keys(NetworkConsole::targets($this->record)));

        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_show')
                ->label(fn () => $this->only === null ? 'Showing all' : 'Showing ' . implode(', ', $this->only))
                ->icon('tabler-filter')
                ->color('gray')
                ->fillForm(fn () => ['only' => $this->only ?? array_keys($options())])
                ->schema([CheckboxList::make('only')->label('Show')->options($options)->columns(3)->bulkToggleable()])
                ->action(function (array $data) use ($options) {
                    $this->only = count($data['only']) === count($options()) ? null : array_values($data['only']);
                }),
            Action::make('exclude_live')
                ->label(fn () => $this->live ? 'Pause' : 'Resume')
                ->icon(fn () => $this->live ? 'tabler-player-pause' : 'tabler-player-play')
                ->color('gray')
                ->action(fn () => $this->live = !$this->live),
            Action::make('exclude_run')
                ->label('Run a command')
                ->icon('tabler-terminal-2')
                ->keyBindings(['mod+k'])
                ->modalWidth('lg')
                ->fillForm(fn () => ['targets' => array_keys(array_filter($options(), fn ($l) => $l !== 'proxy'))])
                ->schema([
                    TextInput::make('command')
                        ->required()
                        ->autofocus()
                        ->placeholder('say Hello everyone')
                        ->helperText('Without the slash. Runs as the console on each server.'),
                    CheckboxList::make('targets')
                        ->label('On')
                        ->options($options)
                        ->required()
                        ->columns(3)
                        ->bulkToggleable(),
                ])
                ->modalSubmitActionLabel('Run')
                ->action(function (array $data) {
                    $result = app(NetworkConsole::class)->send($this->record, $data['command'], array_values($data['targets']));
                    NetworkLog::record($this->record, 'console', 'Ran "' . ltrim(trim($data['command']), '/') . '" on ' . implode(', ', $result['sent']));
                    Notification::make()
                        ->title('Sent to ' . count($result['sent']) . ' server(s)')
                        ->body($result['failed'] === [] ? null : 'Not running: ' . implode(', ', $result['failed']))
                        ->color($result['failed'] === [] ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    public function consoleHtml(): HtmlString
    {
        $result = app(NetworkConsole::class)->lines($this->record, 400, $this->only);
        $palette = ['#60a5fa', '#f472b6', '#34d399', '#fbbf24', '#a78bfa', '#f87171', '#22d3ee', '#fb923c'];
        $labels = array_keys(NetworkConsole::targets($this->record));
        $color = fn (string $label) => $label === 'proxy' ? '#9ca3af' : $palette[array_search($label, $labels, true) % count($palette)];
        $width = max(5, ...array_map('mb_strlen', $labels ?: ['proxy']));

        $html = '';
        foreach ($result['lines'] as $line) {
            $text = e($line['text']);
            $style = match (true) {
                (bool) preg_match('/\b(ERROR|SEVERE)\]|Exception|^\s+at /', $line['text']) => 'color:#f87171',
                (bool) preg_match('/\bWARN\]/', $line['text']) => 'color:#fbbf24',
                default => '',
            };
            $html .= '<div style="white-space:pre-wrap;word-break:break-word"><span style="color:' . $color($line['server']) . ';display:inline-block;min-width:' . ($width + 1) . 'ch">' . e($line['server']) . '</span>'
                . '<span style="' . $style . '">' . $text . '</span></div>';
        }
        foreach ($result['errors'] as $label => $error) {
            $html .= '<div style="color:#9ca3af"><span style="color:' . $color($label) . '">' . e($label) . '</span> ' . e($error) . '</div>';
        }
        if ($html === '') {
            $html = '<div style="color:#9ca3af">No servers in this network yet.</div>';
        }

        return new HtmlString('<div ' . ($this->live ? 'wire:poll.5s' : '') . ' x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight"'
            . ' style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.45;background:#0b0d10;color:#d1d5db;border-radius:10px;padding:12px 14px;height:70vh;overflow-y:auto">'
            . $html . '</div>');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Html::make(fn () => $this->consoleHtml())]);
    }
}
