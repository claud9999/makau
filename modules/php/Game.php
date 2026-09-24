<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\NewHand;
use Bga\GameFramework\Components\Counters\PlayerCounter;
use Bga\Games\makaucloudnein\Cards\CardManager;

class Game extends \Bga\GameFramework\Table
{
    public array $card_types;

    public function __construct()
    {
        parent::__construct();

        $this->cardManager = new CardManager($this);
        $this->cards = $this->cardManager->cards;

        $this->card_types = [
            "suits" => [
                1 => [
                    'name' => clienttranslate('Spade')
                ],
                2 => [
                    'name' => clienttranslate('Heart')
                ],
                3 => [
                    'name' => clienttranslate('Club')
                ],
                4 => [
                    'name' => clienttranslate('Diamond')
                ]
            ],
            "ranks" => [
                2 => ['name' => 'Two'],
                3 => ['name' => 'Three'],
                4 => ['name' => 'Four'],
                5 => ['name' => 'Five'],
                6 => ['name' => 'Six'],
                7 => ['name' => 'Seven'],
                8 => ['name' => 'Eight'],
                9 => ['name' => 'Nine'],
                10 => ['name' => 'Ten'],
                11 => ['name' => clienttranslate('Jack')],
                12 => ['name' => clienttranslate('Queen')],
                13 => ['name' => clienttranslate('King')],
                14 => ['name' => clienttranslate('Ace')],
                15 => ['name' => clienttranslate('Joker')]
            ]
        ];
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $cards = $this->cards;
        $globals = $this->bga->globals;
        $args = [];

        $player_ids = array_keys($this->loadPlayersBasicInfos());
        $args['player_ids'] = $player_ids;

        for ($i = 0; $i < count($player_ids); $i++) {
            $player_id = $player_ids[$i];
            $args["skip_{$player_id}"] = $globals->get("skip_{$player_id}");
            $args["draw_{$player_id}"] = $globals->get("draw_{$player_id}");
            $args["hand_{$player_id}"] = $cards->countItemsInLocation(['hand', $player_id]);
        }

        $args['player_id'] = $currentPlayerId;
        $args['draw'] = $globals->get('draw') + 0;
        $args['skip'] = $globals->get('skip') + 0;
        $args["draw_{$currentPlayerId}"] = $globals->get("draw_{$currentPlayerId}");
        $args["skip_{$currentPlayerId}"] = $globals->get("skip_{$currentPlayerId}");
        $args['suit_demand'] = $globals->get('suit_demand');
        $args['rank_demand'] = $globals->get('rank_demand');
        $args['last_jack'] = $globals->get('last_jack');
        $args['fw_player_id'] = $globals->get('fw_player_id');
        $args['bw_player_id'] = $globals->get('bw_player_id');
        $args['drew'] = $globals->get('drew');

        $args['deck'] = $cards->countItemsInLocation('deck');
        $args['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId]);
        $args['discards'] = $cards->getItemsInLocation('discard')->values();

        return $args;
    }

    public function reset()
    {
        $globals = $this->bga->globals;
        $player_ids = array_keys($this->loadPlayersBasicInfos());

        for ($i = 0; $i < count($player_ids); $i++) {
            $player_id = $player_ids[$i];
            $globals->set("draw_{$player_id}", 0);
            $globals->set("skip_{$player_id}", 0);
        }

        $globals->set('fw_player_id', $this->getPlayerIdByNo(1));
        $globals->set('bw_player_id', 0);
        $globals->set('drew', 0);
        $globals->set('draw', 0);
        $globals->set('skip', 0);
        $globals->set('suit_demand', 0);
        $globals->set('rank_demand', 0);
        $globals->set('last_jack', 0);

        $this->cardManager->deal();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"])
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        $this->reset();

        return NewHand::class;
    }

    public function err($currentPlayerId, $message)
    {
        $this->notify->player(
            $currentPlayerId,
            'InvalidPlay',
            $message,
            ['message' => $message]
        );
        return null;
    }
}
