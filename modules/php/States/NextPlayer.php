<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\Games\makaucloudnein\States\GameOn;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;


class NextPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 32,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('next player'),
        );
    }

    function onEnteringState()
    {
        $globals = $this->bga->globals;
        $game = $this->game;

        $active_player_no = $game->getPlayerNoById($globals->get('active_player_id'));
        $active_player_no++;
        if ($active_player_no > $game->getPlayerCount()) $active_player_no = 1;

        $next_player_id = $game->getPlayerIdByNo($active_player_no);
        $last_jack = $globals->get('last_jack');
        if ($next_player_id == $last_jack) {
            $globals->set('last_jack', 0);
            $globals->set('rank_demand', 0);
        }

        $globals->set('active_player_id', $next_player_id);
        $globals->set('drew', 0);

        $game->bga->notify->all('NextPlayer', '', [
            'active_player_id' => $next_player_id,
        ]);

        return GameOn::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
