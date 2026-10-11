<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class GameOver extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 98,
            type: StateType::GAME,
            descriptionMyTurn: clienttranslate('game over')
        );
    }

    public function onEnteringState()
    {
    }
}
