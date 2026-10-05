<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;

class GameOver extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 98,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('game over')
        );
    }

    public function onEnteringState()
    {
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player {$playerId}");
    }

}
