<?php

namespace Bga\Games\makaucloudnein\Cards;

use Bga\GameFramework\Components\ItemManager\ItemLocation;
use Bga\GameFramework\Components\ItemManager\ItemManager;
use Bga\Games\makaucloudnein\Game;
use Bga\Games\makaucloudnein\Cards\Card;

class CardManager
{
    public ItemManager $cards;

    public function __construct(Game $game)
    {
        $this->cards = $game->bga->itemManagerFactory->createItemManager(
            Card::class,
            locations: ItemLocation::getDefaults(),
        );
        $this->game = $game;
    }

    public function initDb()
    {
        $this->cards->initDb();
    }

    public function reset()
    {
        $cards = $this->cards;
        $cards->moveAllItemsInLocation(null, 'deck');
        $cards->shuffle('deck');
    }

    public function deal()
    {
        $player_ids = array_keys($this->game->loadPlayersBasicInfos());
        $cards = $this->cards;

        $this->reset();

        $discard = $cards->pickItem('deck', 'discard');
        while ($discard->rank < 5 || $discard->rank > 10)
            $discard = $cards->pickItem('deck', 'discard');
        $discards = $cards->getItemsInLocation('discard')->values();

        $args = [
            'deck' => $cards->countItemsInLocation('deck'),
            'discards' => $discards,
        ];

        foreach ($player_ids as $player_id) {
            $args["hand_{$player_id}"] = 5;
        }

        foreach ($player_ids as $player_id) {
            $args['hand'] = $cards->pickItems(10, 'deck', ['hand', (int)$player_id])->values();
            $this->game->bga->notify->player((int)$player_id, 'NewHand', '', $args);
        }
    }

    public function setup()
    {
        $cards = [];
        foreach ([1, 2, 3, 4] as $suit) {
            foreach (range(2, 14) as $rank) {
                $cards[] = [
                    'location' => 'deck',
                    'suit' => $suit,
                    'rank' => $rank,
                ];
            }
        }
        $cards[] = [
            'location' => 'deck',
            'suit' => 0,
            'rank' => 15
        ];
        $cards[] = [
            'location' => 'deck',
            'suit' => 0,
            'rank' => 15
        ];
        $this->cards->createItems($cards);
    }
}
