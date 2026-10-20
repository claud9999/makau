<?php

declare(strict_types=1);

namespace Bga\Games\Makau\States;

use Bga\Games\Makau\States\GameOn;

use Bga\Games\Makau\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class GameStart extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 2,
            type: StateType::GAME,
            descriptionMyTurn: clienttranslate('game starting')
        );
    }

    public function onEnteringState()
    {
        return GameOn::class;
    }
}
