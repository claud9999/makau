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
    }

    public function initDb()
    {
        $this->cards->initDb();
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
        $this->cards->createItems($cards);
        $this->cards->shuffle('deck');
    }
}
