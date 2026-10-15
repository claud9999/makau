<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\GameOn;
use Bga\Games\makaucloudnein\States\NewHand;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class NextPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 35,
            type: StateType::GAME,
            descriptionMyTurn: clienttranslate('next player')
        );
    }

    public function onEnteringState(int $activePlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        $st = json_decode($globals->get('state'), true);

        $handCards = $cards->countItemsInLocation(['hand', $activePlayerId]);
        if ($handCards == 0)
            return NewHand::class;

        if ($st['reverse'])
            $playerId = $this->game->activePrevPlayer();
        else
            $playerId = $this->game->activeNextPlayer();

        $this->game->giveExtraTime($playerId);

        return GameOn::class;
    }
}
