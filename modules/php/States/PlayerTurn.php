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
        $card = $game->cards->pickCard('deck', $activePlayerId);

        $game->notify->all('drawCard', clienttranslate('${player_name} takes a card from the deck'), [
            'playerId' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            '_private' => [
                $activePlayerId => new NotificationMessage(clienttranslate('You take ${_private.rank} of ${_private.suit} from the deck'), [
                    'rank' => $game->card_types['types'][$card['type_arg']]['name'],
                    'suit' => $game->card_types['suits'][$card['type']]['name'],
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

        $top = $game->cards->getCardOnTop('discard');

        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $currentCard = $game->cards->getCard($cardId);
            if ($currentCard['type'] == $top['type'] || $currentCard['type_arg'] == $top['type_arg']) {
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
                        'top_rank' => $game->card_types['types'][$top['type_arg']]['name'],
                        'top_suit' => $game->card_types['suits'][$top['type']]['name'],
                        'card_rank' => $game->card_types['types'][$currentCard['type_arg']]['name'],
                        'card_suit' => $game->card_types['suits'][$currentCard['type']]['name']
                    ]
                );
                return null; // Stop the action if the play is invalid
            }
        }

        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $currentCard = $game->cards->getCard($cardId);

            $game->cards->insertCardOnExtremePosition($cardId, 'discard', true); // Move the card to the "discard" location (the card is now on the table)
            $game->notify->all(
                'playCard',
                clienttranslate('${player_name} plays ${rank} of ${suit}'),
                [
                    'i18n' => array('suit', 'rank'),
                    'card' => $currentCard,
                    'player_id' => $activePlayerId,
                    'player_name' => $game->getPlayerNameById($activePlayerId),
                    'rank' => $game->card_types['types'][$currentCard['type_arg']]['name'],
                    'suit' => $game->card_types['suits'][$currentCard['type']]['name']
                ]
            );
        }
        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
