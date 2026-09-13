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
        $game = $this->game;
        $cards = $game->cards;
        $player_ids = $this->gamestate->getActivePlayerList();
        $globals = $this->bga->globals;

        $this->gamestate->setAllPlayersMultiactive();

        $globals->set('active_player_no', 1);
        $globals->set('drew', 0);
        $globals->set('suit_demand', 0);
        $globals->set('rank_demand', 0);
        $globals->set('last_jack', 0);
        $globals->set("draw", 0);
        $globals->set("skip", 0);

        $cards->moveAllItemsInLocation(null, 'deck');
        $cards->shuffle('deck');

        $discard = $cards->pickItem('deck', 'discard');
        while ($discard->rank < 5 || $discard->rank > 10)
            $discard = $cards->pickItem('deck', 'discard');

        $discards = $cards->getItemsInLocation('discard')->values();

        $hand = $cards->getItemsInLocation('hand', $game->getCurrentPlayerId());

        $args = [
                'deck' => $cards->countItemsInLocation('deck'),
                'hand' => $hand,
                'discards' => $discards,
                'active_player_no' => 1
        ];

        foreach ($player_ids as $player_id) {
            $globals->set("skip_{$player_id}", 0);
            $args["hand_{$plyayer_id}"] = 5;
        }

        foreach ($player_ids as $player_id) {
            $hand = $cards->pickItems(5, 'deck', ['hand', (int)$player_id]);
            $game->bga->notify->player((int)$player_id, 'NewHand', '', $args);
        }
        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}