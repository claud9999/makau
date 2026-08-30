<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;

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
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlay(int $activePlayerId, array $args)
    {
        $cards = $args['cards'];
        for ($i = 0; $i < count($cards); $i++) {
            $cardId = $cards[$i];
            $currentCard = $game->cards->getCard($cardId);
            $game->cards->moveCard($cardId, 'discards'); // Move the card to the "discards" location (the card is now on the table)
            $game->notify->all(
                'playCard',
                clienttranslate('${player_name} plays ${value_displayed} ${color_displayed}'),
                [
                    'i18n' => array('color_displayed', 'value_displayed'),
                    'card' => $currentCard,
                    'player_id' => $activePlayerId,
                    'player_name' => $game->getPlayerNameById($activePlayerId),
                    'value_displayed' => $game->card_types['types'][$currentCard['type_arg']]['name'],
                    'color_displayed' => $game->card_types['suites'][$currentCard['type']]['name']
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
