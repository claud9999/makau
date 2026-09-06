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


class GameOn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('${you} may play cards or draw')
        );
    }

    function onEnteringState() {}

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;


        if ($this->bga->globals->get('drew')) return;
        $this->bga->globals->set('drew', 1);

        $draw_count = $this->bga->globals->get('draw_count');
        if ($draw_count == 0) $draw_count = 1;
        $this->game->debug("ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ Player $currentPlayerId draws $draw_count cards.");

        $cards = $this->game->cards->pickItems($draw_count, 'deck', ['hand', $currentPlayerId])->values();
        $this->game->debug("ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ Player $currentPlayerId drew cards: " . json_encode($cards));
        $this->bga->globals->set('draw_count', 0);

        $this->game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes a card from the deck'),
            [
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
                '_private' => [
                    $currentPlayerId => [
                        'cards' => $cards
                    ]
                ]
            ]
        );

        return null;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cards, int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;

        $discards = $this->game->cards->getItemsInLocation('discard');
        $top = $this->game->cards->getItemOnTop('discard');
        $playedCards = [];

        // check all the cards to make sure they can be played in sequence
        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $card = $this->game->cards->getItemById($cardId);
            if (
                $card->suit == $top->suit
                || $card->rank == $top->rank
                || $top->rank == 12 // queen
                || $card->rank == 12 // queen
            ) {
                $top = $card;
            } else {
                $this->game->notify->player(
                    $currentPlayerId,
                    "InvalidPlay",
                    clienttranslate('You cannot play a ${card_rank} of ${card_suit} on a ${top_rank} of ${top_suit}'),
                    [
                        'i18n' => array('top_rank', 'top_suit', 'card_rank', 'card_suit'),
                        'top' => $top,
                        'card' => $card,
                        'top_rank' => $game->card_types['ranks'][$top->rank]['name'],
                        'top_suit' => $game->card_types['suits'][$top->suit]['name'],
                        'card_rank' => $game->card_types['ranks'][$card->rank]['name'],
                        'card_suit' => $game->card_types['suits'][$card->suit]['name']
                    ]
                );
                return null; // Stop the action if the play is invalid
            }
            $playedCards[] = $card;
        }

        $skip_count = $this->bga->globals->get('skip_count');
        $draw_count = $this->bga->globals->get('draw_count');

        for ($i = 0; $i < count($cards); $i++) {
            $card = $this->game->cards->getItemById($cards[$i]);
            if ($card->rank == 2 || $card->rank == 3) {
                $draw_count += $card->rank;
            }
            if ($card->rank == 4) {
                $skip_count++;
            }
        }

        $this->bga->globals->set('skip_count', $skip_count);
        $this->bga->globals->get('draw_count', $draw_count);

        // valid play, move the cards to the discard pile
        $this->game->cards->moveItems($cards, 'discard');
        $this->game->notify->all(
            'PlayCards',
            '',
            [
                'cards' => $playedCards,
                'skip_count' => $skip_count,
                'draw_count' => $draw_count
            ]
        );

        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
