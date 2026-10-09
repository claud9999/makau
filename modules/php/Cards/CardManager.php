<?php

namespace Bga\Games\makaucloudnein\Cards;

use Bga\GameFramework\Components\ItemManager\ItemLocation;
use Bga\GameFramework\Components\ItemManager\ItemManager;
use Bga\Games\makaucloudnein\Game;
use Bga\Games\makaucloudnein\Cards\Card;

class CardManager
{
    public ItemManager $cards;
    public Game $game;

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

        foreach ($player_ids as $player_id)
            $cards->pickItems(5, 'deck', ['hand', (int)$player_id])->values();

        $discard = $cards->pickItem('deck', 'discard');
        // keep adding to discard until we find a non-action card
        while ($discard->rank < 5 || $discard->rank > 10)
            $discard = $cards->pickItem('deck', 'discard');
    }

    public function setup()
    {
        $cards = [];
        foreach ([Game::SPADE, Game::HEART, Game::CLUB, Game::DIAMOND] as $suit) {
            foreach (range(1, 13) as $rank) {
                $cards[] = [
                    'location' => 'deck',
                    'suit' => $suit,
                    'rank' => $rank,
                    'joker' => 0
                ];
            }
        }
        $cards[] = [
            'location' => 'deck',
            'suit' => 0,
            'rank' => Game::JOKER,
            'joker' => 1
        ];
        $cards[] = [
            'location' => 'deck',
            'suit' => 0,
            'rank' => Game::JOKER,
            'joker' => 1
        ];
        $this->cards->createItems($cards);
    }
}
