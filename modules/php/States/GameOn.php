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
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('ActivePlayer'))
            return null;

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('ActivePlayer'))
            return null;

        if ($this->bga->globals->get('Drew')) return;
        $this->bga->globals->set('Drew', 1);

        $game = $this->game;
        $card = $game->cards->pickItem('deck', ['hand', $currentPlayerId]);

        $game->notify->all('DrawCard', clienttranslate('${player_name} takes a card from the deck'), [
            'playerId' => $currentPlayerId,
            'player_name' => $game->getPlayerNameById($currentPlayerId),
            '_private' => [
                $currentPlayerId => new NotificationMessage(clienttranslate('You take ${_private.rank} of ${_private.suit} from the deck'), [
                    'rank' => $game->card_types['ranks'][$card->rank]['name'],
                    'suit' => $game->card_types['suits'][$card->suit]['name'],
                    'card' => $card,
                    'i18n' => ['rank', 'suit']
                ])
            ]
        ]);

        return null;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cards, int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('ActivePlayer'))
            return null;

        $game = $this->game;
        $game->debug("Player $currentPlayerId plays cards: " . implode(', ', $cards));
        $discards = $game->cards->getItemsInLocation('discard');
        $top = $game->cards->getItemOnTop('discard');
        $playedCards = [];

        // check all the cards to make sure they can be played in sequence
        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $currentCard = $game->cards->getItemById($cardId);
            if (
                $currentCard->suit == $top->suit
                || $currentCard->rank == $top->rank
                || $top->rank == 12 // queen
                || $currentCard->rank == 12 // queen
            ) {
                $top = $currentCard;
            } else {
                $game->notify->player(
                    $currentPlayerId,
                    "InvalidPlay",
                    clienttranslate('You cannot play a ${card_rank} of ${card_suit} on a ${top_rank} of ${top_suit}'),
                    [
                        'i18n' => array('top_rank', 'top_suit', 'card_rank', 'card_suit'),
                        'top' => $top,
                        'card' => $currentCard,
                        'top_rank' => $game->card_types['ranks'][$top->rank]['name'],
                        'top_suit' => $game->card_types['suits'][$top->suit]['name'],
                        'card_rank' => $game->card_types['ranks'][$currentCard->rank]['name'],
                        'card_suit' => $game->card_types['suits'][$currentCard->suit]['name']
                    ]
                );
                return null; // Stop the action if the play is invalid
            }
            $playedCards[] = $currentCard;
        }

        // valid play, move the cards to the discard pile
        $game->cards->moveItems($cards, 'discard');
        $game->notify->all(
            'PlayCards',
            '',
            [
                'cards' => $playedCards
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
