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


class PlayerTurn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::ACTIVE_PLAYER, // This state type means that one player is active and can do actions
            description: clienttranslate('${actplayer} must play a card'), // We tell OTHER players what they are waiting for
            descriptionMyTurn: clienttranslate('${you} must play a card'), // We tell the ACTIVE player what they must do
            // We suround the code with clienttranslate() so that the text is sent to the client for translation (this will enable the game to support other languages)
        );
    }

    #[PossibleAction]
    public function actPass(int $activePlayerId)
    {
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $activePlayerId)
    {
        $game = $this->game;
        $card = $game->cards->pickItem('deck', ['hand', $activePlayerId]);

        $game->notify->all('drawCard', clienttranslate('${player_name} takes a card from the deck'), [
            'playerId' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            '_private' => [
                $activePlayerId => new NotificationMessage(clienttranslate('You take ${_private.rank} of ${_private.suit} from the deck'), [
                    'rank' => $game->card_types['ranks'][$card->rank]['name'],
                    'suit' => $game->card_types['suits'][$card->suit]['name'],
                    'card' => $card,
                    'i18n' => ['rank', 'suit']
                ])
            ]
        ]);
        return;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cards, int $activePlayerId)
    {
        $game = $this->game;
        $game->debug("Player $activePlayerId plays cards: " . implode(', ', $cards));
        $discards = $game->cards->getItemsInLocation('discard');
        $top = $game->cards->getItemOnTop('discard');

        $game->debug("Discard pile now has cards: " . json_encode($game->getCardNames($discards)));

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
                    $activePlayerId,
                    "invalidPlay",
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
        }

        // valid play, move the cards to the discard pile
        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $currentCard = $game->cards->getItemById($cardId);

            $game->cards->moveItem($cardId, 'discard');
            $game->notify->all(
                'playCard',
                clienttranslate('${player_name} plays ${rank} of ${suit}'),
                [
                    'i18n' => array('suit', 'rank'),
                    'card' => $currentCard,
                    'player_id' => $activePlayerId,
                    'player_name' => $game->getPlayerNameById($activePlayerId),
                    'rank' => $game->card_types['ranks'][$currentCard->rank]['name'],
                    'suit' => $game->card_types['suits'][$currentCard->suit]['name']
                ]
            );
        }

        $game->debug("Discard pile now has cards: " . json_encode($game->getCardNames($game->cards->getItemsInLocation('discard'))));

        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
