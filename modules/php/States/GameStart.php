<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\GameOn;

use Bga\Games\makaucloudnein\Game;
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
