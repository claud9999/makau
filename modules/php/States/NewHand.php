<?php
declare(strict_types=1);
namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class NewHand extends GameState
{
  public function __construct(protected Game $game)
  {
    parent::__construct(
      $game,
      id: 2,
      type: StateType::GAME,
      updateGameProgression: true,
    );
  }

  // The action we do when entering the state
    public function onEnteringState()
    {
        $game = $this->game;

        $game->cards->moveAllCardsInLocation(null, "deck");

        $game->cards->shuffle('deck');

        $players = $game->loadPlayersBasicInfos();
        foreach ($players as $player_id => $player) {
            $cards = $game->cards->pickCards(5, 'deck', $player_id);
            // Notify player about his cards
            $this->bga->notify->player($player_id, 'NewHand', '', array('cards' => $cards));
        }

        $game->cards->pickCardForLocation('deck', 'discards');

        $first_player = (int) $this->game->getActivePlayerId();
        $this->game->gamestate->changeActivePlayer($first_player);

        return PlayerTurn::class;
    }
}