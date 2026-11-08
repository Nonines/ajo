<?php

namespace App\Filament\Pages;

use App\Models\Staff;
use App\Support\Demo\DemoTimeMachine;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Controls shared demo time while preserving restart progress and administrator authorization.
 */
class TimeMachine extends Page
{
    protected string $view = 'filament.pages.time-machine';

    public string $expectedInstant = '';

    /**
     * Restricts the shared demo clock to active administrators with simulation permission.
     */
    public static function canAccess(): bool
    {
        $staff = Filament::auth()->user();

        return app()->environment('demo') && $staff instanceof Staff && $staff->is_active
            && $staff->hasRole('admin') && $staff->can('simulator.use');
    }

    /**
     * Captures the current demo instant to reject stale advance submissions.
     */
    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->expectedInstant = app(DemoTimeMachine::class)->state()['current_at'];
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('advance')->label('Advance time')->schema([
                TextInput::make('days')->label('Days to advance')->integer()->minValue(1)
                    ->maxValue(config('demo.maximum_advance_days'))->default(90)->required(),
            ])->modalDescription('Advances time for every account. Due financial work runs in order. Staff decisions and payment outcomes remain manual.')
                ->action(function (array $data): void {
                    $staff = $this->authorizeAdmin();

                    try {
                        app(DemoTimeMachine::class)->start($staff, (int) $data['days'], $this->expectedInstant);
                        $this->process();
                    } catch (DomainException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('resume')->label('Resume processing')->action(function (): void {
                app(DemoTimeMachine::class)->resume($this->authorizeAdmin());
                $this->process();
            }),
        ];
    }

    /**
     * Continues pending demo work and refreshes the expected instant after each poll.
     */
    public function process(): void
    {
        $this->authorizeAdmin();
        $machine = app(DemoTimeMachine::class);

        if ($machine->state()['target_at'] !== null) {
            try {
                $machine->process();
            } catch (DomainException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();
            }
        }

        $this->expectedInstant = $machine->state()['current_at'];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->authorizeAdmin();

        return ['demo' => app(DemoTimeMachine::class)->state()];
    }

    /**
     * Rechecks page access and service authorization for every interactive operation.
     */
    private function authorizeAdmin(): Staff
    {
        abort_unless(static::canAccess(), 403);
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof Staff, 403);
        app(DemoTimeMachine::class)->authorize($staff);

        return $staff;
    }
}
