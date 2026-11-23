<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Actions;

use App\Models\Node;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Ginjer\Network\Jobs\RunSplit;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\Split\ResourcePlanner;
use Ginjer\Network\Services\Split\SplitPlanner;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Split a server into shards": pick a server, get a plan (regions balanced by where Nodes
 * players are, RAM per shard, a node and port for each new one), adjust names and RAM, run it.
 */
class SplitServerAction
{
    public static function make(): Action
    {
        return Action::make('exclude_split')
            ->label('Split a server into shards')
            ->icon('tabler-arrows-split-2')
            ->color('primary')
            ->modalHeading('Split a server into shards')
            ->modalWidth(Width::FiveExtraLarge)
            ->steps([
                Step::make('Server')
                    ->description('What to split')
                    ->schema([
                        Select::make('source_id')
                            ->label('Server to split')
                            ->helperText('It becomes the Nodes leader and keeps the area around spawn, the Nether and the End. The new shards are copies of it.')
                            ->required()
                            ->searchable()
                            ->options(fn () => Server::query()
                                ->with('node')
                                ->whereNotIn('id', NetworkMember::query()->pluck('server_id'))
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Server $s) => [$s->id => "$s->name ({$s->node->name}, " . self::gb($s->memory) . ')'])),
                        TextInput::make('players')
                            ->label('Players online at peak')
                            ->numeric()->integer()->minValue(1)
                            ->placeholder('Guess from Nodes residents')
                            ->helperText('Sets the shard count and RAM.'),
                        Select::make('count')
                            ->label('Shards')
                            ->placeholder('Automatic (about ' . ResourcePlanner::PLAYERS_PER_SHARD . ' players each)')
                            ->options(array_combine(range(2, 8), range(2, 8))),
                        Select::make('node_ids')
                            ->label('Nodes for the new shards')
                            ->multiple()
                            ->placeholder('The same node as the server')
                            ->options(fn () => Node::query()->orderBy('name')->pluck('name', 'id'))
                            ->helperText('Each new shard goes on the chosen node with the most free memory.'),
                        TextInput::make('world')
                            ->label('World to split')
                            ->default('world')
                            ->required(),
                    ])
                    ->afterValidation(function (Get $get, Set $set, SplitPlanner $planner) {
                        $source = Server::with('node', 'allocation')->findOrFail($get('source_id'));
                        try {
                            $plan = $planner->plan(
                                $source,
                                filled($get('players')) ? (int) $get('players') : null,
                                filled($get('count')) ? (int) $get('count') : null,
                                array_map('intval', $get('node_ids') ?? []),
                                (string) $get('world'),
                            );
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Couldn\'t read ' . $source->name)
                                ->body('Its node must be online so the plan can read Nodes\' files. ' . $exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $set('plan', json_encode($plan));
                        $set('shards', array_map(fn ($s) => ['info' => self::shardInfo($s), 'name' => $s['name'], 'memory' => $s['memory']], $plan['shards']));
                        $set('network_name', $source->name . ' network');
                    }),

                Step::make('Plan')
                    ->description('Check and adjust')
                    ->schema([
                        Hidden::make('plan'),
                        Html::make(fn (Get $get) => self::summary(self::plan($get))),
                        Callout::make('Can\'t split like this')
                            ->danger()
                            ->description(fn (Get $get) => new HtmlString(self::list(self::plan($get)['errors'] ?? [])))
                            ->visible(fn (Get $get) => (self::plan($get)['errors'] ?? []) !== []),
                        Callout::make('Worth knowing')
                            ->warning()
                            ->description(fn (Get $get) => new HtmlString(self::list(self::plan($get)['warnings'] ?? [])))
                            ->visible(fn (Get $get) => (self::plan($get)['warnings'] ?? []) !== []),
                        Repeater::make('shards')
                            ->label('Shards')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                Hidden::make('info'),
                                Html::make(fn (Get $get) => new HtmlString((string) $get('info')))
                                    ->columnSpan(2),
                                TextInput::make('name')
                                    ->label('Shard name')
                                    ->required()
                                    ->alphaDash()
                                    ->maxLength(64)
                                    ->distinct(),
                                TextInput::make('memory')
                                    ->label('RAM')
                                    ->numeric()->integer()->minValue(1024)->step(512)
                                    ->suffix('MB')
                                    ->required(),
                            ]),
                        Section::make('Network')
                            ->columns(3)
                            ->schema([
                                TextInput::make('network_name')
                                    ->label('Network name')
                                    ->required()
                                    ->unique('ginjer_networks', 'name'),
                                Select::make('redis_server_id')
                                    ->label('Redis server')
                                    ->placeholder('Add later')
                                    ->options(fn () => self::freeServers()),
                                Select::make('proxy_server_id')
                                    ->label('Velocity proxy')
                                    ->placeholder('Add later')
                                    ->options(fn () => self::freeServers()),
                                TextInput::make('redis_host')->label('Redis host')->default('127.0.0.1')->required(),
                                TextInput::make('redis_port')->label('Redis port')->numeric()->default(6379)->required(),
                                TextInput::make('redis_password')->label('Redis password')->password()->revealable(),
                            ]),
                        Callout::make('Stop the server before you start')
                            ->info()
                            ->description('The world is copied while the server is offline, so nothing changes halfway. Copying runs in the background; the result shows up in your notifications.'),
                    ]),
            ])
            ->modalSubmitActionLabel('Split')
            ->action(function (array $data) {
                $plan = json_decode($data['plan'] ?? '', true);
                if (!is_array($plan) || ($plan['errors'] ?? []) !== []) {
                    Notification::make()->title('The plan has problems, so nothing was started')->danger()->send();

                    throw new Halt();
                }

                // the admin's names and RAM
                $edits = array_values($data['shards'] ?? []);
                $oldNames = array_column($plan['shards'], 'name');
                foreach ($plan['shards'] as $i => &$shard) {
                    $shard['name'] = (string) ($edits[$i]['name'] ?? $shard['name']);
                    $shard['memory'] = (int) ($edits[$i]['memory'] ?? $shard['memory']);
                }
                unset($shard);
                if (count(array_unique(array_column($plan['shards'], 'name'))) !== count($oldNames)) {
                    Notification::make()->title('Every shard needs its own name')->danger()->send();

                    throw new Halt();
                }

                RunSplit::dispatch($plan, [
                    'name' => $data['network_name'],
                    'redis_host' => $data['redis_host'],
                    'redis_port' => $data['redis_port'],
                    'redis_password' => $data['redis_password'] ?? null,
                    'redis_server_id' => $data['redis_server_id'] ?? null,
                    'proxy_server_id' => $data['proxy_server_id'] ?? null,
                ], (int) auth()->id());

                Notification::make()
                    ->title('Split started')
                    ->body('Creating ' . (count($plan['shards']) - 1) . ' shard(s) and copying the world. You\'ll get a notification when it\'s done.')
                    ->success()
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    private static function plan(Get $get): array
    {
        $plan = json_decode((string) $get('plan'), true);

        return is_array($plan) ? $plan : [];
    }

    /** @return array<int, string> */
    private static function freeServers(): array
    {
        return Server::query()->whereNotIn('id', NetworkMember::query()->pluck('server_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @param  array<string, mixed>  $plan */
    private static function summary(array $plan): HtmlString
    {
        if ($plan === []) {
            return new HtmlString('');
        }
        $stats = $plan['stats'];
        $basis = $stats['from_nodes']
            ? "Balanced by where Nodes players are: {$stats['territories']} territories, {$stats['towns']} towns, {$stats['residents']} residents."
            : 'Split evenly around spawn (no Nodes data).';
        $total = array_sum(array_column($plan['shards'], 'memory'));

        return new HtmlString('<p><b>' . count($plan['shards']) . ' shards</b> for about ' . (int) $plan['players'] . ' players at peak, '
            . self::gb($total) . ' RAM in total. ' . e($basis) . '</p>');
    }

    /** @param  array<string, mixed>  $shard  one shard of a plan */
    private static function shardInfo(array $shard): string
    {
        $r = $shard['region'];
        $edge = fn ($v) => abs($v) >= 29999984 ? ($v < 0 ? '−∞' : '∞') : number_format($v, 0, '.', ' ');
        $role = $shard['source'] ? 'Original server, Nodes leader' : 'New server';

        return '<div style="line-height:1.5"><b>' . e($role) . '</b><br>'
            . 'x ' . $edge($r['min-x']) . ' to ' . $edge($r['max-x']) . ', z ' . $edge($r['min-z']) . ' to ' . $edge($r['max-z']) . '<br>'
            . '~' . (int) $shard['players'] . ' players (' . round($shard['weight'] * 100) . '%) · '
            . e($shard['node_name'] ?: 'no node') . ($shard['address'] ? ' · ' . e($shard['address']) : '') . '</div>';
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        return '<ul style="list-style:disc;padding-left:1.2em">' . implode('', array_map(fn ($i) => '<li>' . e($i) . '</li>', $items)) . '</ul>';
    }

    private static function gb(int $mb): string
    {
        return rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB';
    }
}
