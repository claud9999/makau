<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\GameOn;
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

        $this->cardTypes = [
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

        $playerIds = array_keys($this->loadPlayersBasicInfos());
        $args['playerIds'] = $playerIds;

        for ($i = 0; $i < count($playerIds); $i++) {
            $playerId = $playerIds[$i];
            $args["skip{$playerId}"] = $globals->get("skip{$playerId}");
            $args["draw{$playerId}"] = $globals->get("draw{$playerId}");
            $args["hand{$playerId}"] = $cards->countItemsInLocation(['hand', $playerId]);
        }

        $args['playerId'] = $currentPlayerId;
        $args['draw'] = $globals->get('draw') + 0;
        $args['skip'] = $globals->get('skip') + 0;
        $args['rankPick'] = $globals->get('rankPick');
        $args['suitPick'] = $globals->get('suitPick');
        $args['suitDemand'] = $globals->get('suitDemand');
        $args['rankDemand'] = $globals->get('rankDemand');
        $args['lastJack'] = $globals->get('lastJack');
        $args['fwPlayerId'] = $globals->get('fwPlayerId');
        $args['bwPlayerId'] = $globals->get('bwPlayerId');
        $args['drew'] = $globals->get('drew');

        $args['deck'] = $cards->countItemsInLocation('deck');
        $args['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId]);
        $args['discards'] = $cards->getItemsInLocation('discard')->values();

        return $args;
    }

    public function reset()
    {
        $globals = $this->bga->globals;
        $playerIds = array_keys($this->loadPlayersBasicInfos());

        for ($i = 0; $i < count($playerIds); $i++) {
            $playerId = $playerIds[$i];
            $globals->set("draw{$playerId}", 0);
            $globals->set("skip{$playerId}", 0);
        }

        $globals->set('fwPlayerId', $this->getPlayerIdByNo(1));
        $globals->set('bwPlayerId', 0);
        $globals->set('drew', 0);
        $globals->set('draw', 0);
        $globals->set('skip', 0);
        $globals->set('rankPick', 0);
        $globals->set('suitPick', 0);
        $globals->set('suitDemand', 0);
        $globals->set('rankDemand', 0);
        $globals->set('lastJack', 0);

        $this->cardManager->deal();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];

        foreach ($players as $playerId => $player) {
            // Now you can access both $playerId and $player array
            $queryValues[] = vsprintf("(%s, '%s', '%s')", [
                $playerId,
                array_shift($defaultColors),
                addslashes($player["player_name"])
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $queryValues)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        $this->reset();

        return GameOn::class;
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
