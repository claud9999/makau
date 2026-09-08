<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\Games\makaucloudnein\States\NextPlayer;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;


class NewHand extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 2,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('dealing hands'),
        );
    }

    function onEnteringState()
    {
        $cards = $this->game->cards;

        $this->gamestate->setAllPlayersMultiactive();

        $this->bga->globals->set('active_player_no', 1);
        $this->bga->globals->set('skip_count', 0);
        $this->bga->globals->set('draw_count', 0);
        $this->bga->globals->set('drew', 0);

        $cards->moveAllItemsInLocation(null, 'deck');
        $cards->shuffle('deck');

        $discard = $this->game->cards->pickItem('deck', 'discard');
        while ($discard->rank < 5 || $discard->rank > 10)
            $discard = $this->game->cards->pickItem('deck', 'discard');

        foreach ($this->gamestate->getActivePlayerList() as $player_id) {
            $hand = $cards->pickItems(5, 'deck', ['hand', (int)$player_id]);
            $this->game->bga->notify->player((int)$player_id, 'NewHand', '', [
                'deck' => $cards->countItemsInLocation('deck'),
                'hand' => $hand,
                'discard' => $discard,
                'active_player_no' => 1
            ]);
        }
        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
