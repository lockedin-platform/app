<?php

namespace App\Tests\Service;

use App\Entity\Cours;
use App\Service\CoursQuizService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CoursQuizServiceTest extends TestCase
{
    private CoursQuizService $service;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        // Empty API key forces fallback quiz
        $this->service = new CoursQuizService(
            $httpClient,
            new NullLogger(),
            '',
            'https://api.groq.com/openai/v1/chat/completions',
            'llama3-8b-8192'
        );
    }

    public function testGenerateQuizReturnsArray(): void
    {
        $cours = new Cours();
        $cours->setTitre('Introduction au PHP');
        $quiz = $this->service->generateQuizForCours($cours);
        $this->assertIsArray($quiz);
    }

    public function testGenerateQuizHasThreeQuestions(): void
    {
        $cours = new Cours();
        $cours->setTitre('Base de données SQL');
        $quiz = $this->service->generateQuizForCours($cours);
        $this->assertCount(3, $quiz);
    }

    public function testGenerateQuizQuestionStructure(): void
    {
        $cours = new Cours();
        $cours->setTitre('Marketing digital');
        $quiz = $this->service->generateQuizForCours($cours);
        $firstQuestion = $quiz[0];
        $this->assertArrayHasKey('question', $firstQuestion);
        $this->assertArrayHasKey('options', $firstQuestion);
        $this->assertArrayHasKey('answer_index', $firstQuestion);
    }

    public function testGenerateQuizOptionsHasFourChoices(): void
    {
        $cours = new Cours();
        $cours->setTitre('Gestion de projet');
        $quiz = $this->service->generateQuizForCours($cours);
        $this->assertCount(4, $quiz[0]['options']);
    }

    public function testGenerateQuizAnswerIndexIsValid(): void
    {
        $cours = new Cours();
        $cours->setTitre('Comptabilité');
        $quiz = $this->service->generateQuizForCours($cours);
        foreach ($quiz as $q) {
            $this->assertGreaterThanOrEqual(0, $q['answer_index']);
            $this->assertLessThan(count($q['options']), $q['answer_index']);
        }
    }

    public function testGenerateQuizFirstQuestionContainsTitle(): void
    {
        $cours = new Cours();
        $cours->setTitre('Business Plan');
        $quiz = $this->service->generateQuizForCours($cours);
        $this->assertStringContainsString('Business Plan', $quiz[0]['question']);
    }
}
