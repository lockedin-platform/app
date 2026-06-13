<?php

namespace App\Command;

use App\WebSocket\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:websocket:start',
    description: 'Start the WebSocket notification server',
)]
class WebSocketServerCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('LockedIn WebSocket Server');
        $io->info('Starting WebSocket server on ws://127.0.0.1:8090');
        $io->info('Internal push server on tcp://127.0.0.1:8091');
        $io->info('Press Ctrl+C to stop.');

        $server = new Server('127.0.0.1', 8090, 8091);

        try {
            $server->run();
        } catch (\Throwable $e) {
            $io->error('Server error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
