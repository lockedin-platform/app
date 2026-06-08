<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\EventParticipant;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Post;
use App\Entity\PostReaction;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\EventRepository;
use App\Repository\GroupRepository;
use App\Repository\PostReactionRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Service\CommunityAiService;
use App\Service\CommunityEventPdfService;
use App\Service\CommunityPostTranslationService;
use App\Service\CommunityTextModerationService;
use App\Service\CommunityTicketService;
use App\Service\CommunityWeatherService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

#[Route('/community')]
#[IsGranted('ROLE_USER')]
class CommunityController extends AbstractController
{
    private const THREAD_TITLE_MIN_LENGTH = 5;
    private const THREAD_TITLE_MAX_LENGTH = 150;
    private const THREAD_CONTENT_MIN_LENGTH = 15;
    private const THREAD_CONTENT_MAX_LENGTH = 5000;
    private const COMMENT_CONTENT_MIN_LENGTH = 3;
    private const COMMENT_CONTENT_MAX_LENGTH = 2000;
    private const GROUP_NAME_MIN_LENGTH = 3;
    private const GROUP_NAME_MAX_LENGTH = 120;
    private const GROUP_DESCRIPTION_MIN_LENGTH = 15;
    private const GROUP_DESCRIPTION_MAX_LENGTH = 1500;
    private const EVENT_TITLE_MIN_LENGTH = 5;
    private const EVENT_TITLE_MAX_LENGTH = 150;
    private const EVENT_DESCRIPTION_MIN_LENGTH = 15;
    private const EVENT_DESCRIPTION_MAX_LENGTH = 4000;
    private const EVENT_CAPACITY_MAX = 5000;

    private const REACTION_META = [
        PostReaction::TYPE_LIKE => ['label' => 'J\'aime', 'emoji' => '👍'],
        PostReaction::TYPE_LOVE => ['label' => 'J\'adore', 'emoji' => '❤️'],
        PostReaction::TYPE_HAHA => ['label' => 'Haha', 'emoji' => '😂'],
        PostReaction::TYPE_WOW => ['label' => 'Waouh', 'emoji' => '😮'],
        PostReaction::TYPE_SAD => ['label' => 'Sad', 'emoji' => '😢'],
        PostReaction::TYPE_ANGRY => ['label' => 'En colere', 'emoji' => '😡'],
    ];

    private const TRANSLATION_LANGUAGES = [
        'fr' => 'Francais',
        'en' => 'English',
        'ar' => 'Arabe',
    ];

    public function __construct(
        private readonly GroupRepository $groupRepository,
        private readonly EventRepository $eventRepository,
    ) {
    }

    // ========== POSTS ==========
    #[Route('/posts', name: 'app_community_posts')]
    public function posts(PostRepository $repo, Request $request): Response
    {
        return $this->renderPostsPage($repo, $request);
    }

    #[Route('/posts/new', name: 'app_community_post_new', methods: ['POST'])]
    public function newPost(
        Request $request,
        EntityManagerInterface $em,
        PostRepository $repo,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        $content = trim((string) $request->request->get('content', ''));
        $formData = [
            'content' => $content,
        ];

        try {
            $imageUrl = $this->storePostUpload($request->files->get('image'));
        } catch (Throwable $exception) {
            return $this->renderPostsPage($repo, $request, [
                'newPostData' => $formData,
                'newPostErrors' => ['image' => $exception->getMessage()],
            ]);
        }

        if ($content === '' && $imageUrl === null) {
            return $this->renderPostsPage($repo, $request, [
                'newPostData' => $formData,
                'newPostErrors' => ['content' => 'Ajoutez du texte ou choisissez une image avant de publier.'],
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $post = new Post();
        $post->setUser($this->currentUser());
        $post->setContent($moderated['text']);
        $post->setImageUrl($imageUrl);

        $em->persist($post);
        $em->flush();

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le texte a ete legerement modere avant publication.');
        }

        $this->addFlash('success', 'Publication créée !');
        return $this->redirectToRoute('app_community_posts');
    }

    #[Route('/posts/{id}/react', name: 'app_community_post_react', methods: ['POST'])]
    public function reactPost(
        Post $post,
        Request $request,
        EntityManagerInterface $em,
        PostReactionRepository $reactionRepo,
    ): Response
    {
        $user = $this->currentUser();
        $type = strtoupper(trim((string) $request->request->get('reaction', $request->request->get('type', 'LIKE'))));

        if (!isset(self::REACTION_META[$type])) {
            return $this->reactionError($request, 'Reaction invalide.');
        }

        $existing = $reactionRepo->findByPostAndUser($post, $user);

        if ($existing) {
            if ($existing->getReactionType() === $type) {
                $em->remove($existing);
            } else {
                $existing->setReactionType($type);
            }
        } else {
            $reaction = new PostReaction();
            $reaction->setPost($post);
            $reaction->setUser($user);
            $reaction->setReactionType($type);
            $em->persist($reaction);
        }

        $em->flush();
        $em->refresh($post);

        if ($request->isXmlHttpRequest()) {
            return $this->reactionStateResponse($post, $user);
        }

        return $this->redirectToRoute('app_community_posts');
    }

    #[Route('/posts/{id}/react/remove', name: 'app_community_post_unreact', methods: ['POST'])]
    public function unreactPost(
        Post $post,
        Request $request,
        EntityManagerInterface $em,
        PostReactionRepository $reactionRepo,
    ): Response
    {
        $user = $this->currentUser();
        $existing = $reactionRepo->findByPostAndUser($post, $user);

        if ($existing instanceof PostReaction) {
            $em->remove($existing);
            $em->flush();
            $em->refresh($post);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->reactionStateResponse($post, $user);
        }

        return $this->redirectToRoute('app_community_posts');
    }

    #[Route('/posts/{id}/translate', name: 'app_community_post_translate', methods: ['GET'])]
    public function translatePost(
        Post $post,
        Request $request,
        CommunityPostTranslationService $postTranslationService,
    ): JsonResponse
    {
        try {
            $result = $postTranslationService->translate((string) $post->getContent(), (string) $request->query->get('target', 'original'));

            return new JsonResponse([
                'ok' => true,
                'text' => $result['text'],
                'source' => $result['source'],
                'source_label' => $result['source_label'],
                'target' => $result['target'],
                'target_label' => $result['target_label'],
                'is_original' => $result['is_original'],
            ]);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/posts/{id}/update', name: 'app_community_post_update', methods: ['POST'])]
    public function updatePost(
        Post $post,
        Request $request,
        EntityManagerInterface $em,
        PostRepository $repo,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canManagePost($post, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $content = trim((string) $request->request->get('content', ''));
        $oldImageUrl = $post->getImageUrl();
        $imageUrl = $post->getImageUrl();
        $formData = [
            'content' => $content,
            'remove_image' => $request->request->getBoolean('remove_image'),
        ];

        if ($formData['remove_image']) {
            $imageUrl = null;
        }

        try {
            $uploaded = $this->storePostUpload($request->files->get('image'));
        } catch (Throwable $exception) {
            return $this->renderPostsPage($repo, $request, [
                'openPostEditId' => (int) $post->getId(),
                'postEditData' => $formData,
                'postEditErrors' => ['image' => $exception->getMessage()],
            ]);
        }

        if ($uploaded !== null) {
            $imageUrl = $uploaded;
        }

        if ($content === '' && $imageUrl === null) {
            return $this->renderPostsPage($repo, $request, [
                'openPostEditId' => (int) $post->getId(),
                'postEditData' => $formData,
                'postEditErrors' => ['content' => 'Une publication doit contenir du texte ou une image.'],
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $post->setContent($moderated['text']);
        $post->setImageUrl($imageUrl);
        $em->flush();

        if ($oldImageUrl !== $imageUrl) {
            $this->removeCommunityUpload($oldImageUrl);
        }

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le texte a ete legerement modere avant enregistrement.');
        }

        $this->addFlash('success', 'Publication mise a jour.');

        return $this->redirectToRoute('app_community_posts');
    }

    #[Route('/posts/{id}/delete', name: 'app_community_post_delete', methods: ['POST'])]
    public function deletePost(Post $post, EntityManagerInterface $em): Response
    {
        if (!$this->canManagePost($post, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $imageUrl = $post->getImageUrl();
        $em->remove($post);
        $em->flush();
        $this->removeCommunityUpload($imageUrl);
        $this->addFlash('success', 'Publication supprimée.');

        return $this->redirectToRoute('app_community_posts');
    }

    // ========== GROUPS ==========
    #[Route('/groups', name: 'app_community_groups')]
    public function groups(GroupRepository $repo): Response
    {
        $user = $this->currentUser();
        $groups = $repo->findCommunityGroups();

        return $this->render('front/community/groups.html.twig', [
            'groups' => $groups,
            'pendingRequestGroupIds' => $repo->findPendingRequestGroupIdsForUser((int) $user->getId()),
        ]);
    }

    #[Route('/groups/new', name: 'app_community_group_new', methods: ['GET', 'POST'])]
    public function newGroup(
        Request $request,
        EntityManagerInterface $em,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if ($request->isMethod('POST')) {
            $formData = [
                'name' => $this->sanitizeSubmittedTitle((string) $request->request->get('name', '')),
                'description' => $this->sanitizeSubmittedBody((string) $request->request->get('description', '')),
                'is_private' => $request->request->getBoolean('is_private'),
            ];
            $formErrors = $this->validateGroupInput($formData['name'], $formData['description']);

            if ($formErrors !== []) {
                return $this->render('front/community/group_form.html.twig', [
                    'formData' => $formData,
                    'formErrors' => $formErrors,
                ]);
            }

            $moderated = $textModerationService->moderate($formData['description']);
            $group = new Group();
            $group->setName($formData['name']);
            $group->setDescription($moderated['text']);
            $group->setGroupAdmin($this->currentUser());
            $group->setIsPrivate($formData['is_private']);

            $em->persist($group);

            // Add creator as member
            $member = new GroupMember();
            $member->setGroup($group);
            $member->setUser($this->currentUser());
            $em->persist($member);
            $em->flush();

            if ($moderated['changed']) {
                $this->addFlash('info', 'La description a ete legerement moderee avant creation.');
            }

            $this->addFlash('success', 'Groupe créé !');
            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        return $this->render('front/community/group_form.html.twig', [
            'formData' => [
                'name' => '',
                'description' => '',
                'is_private' => false,
            ],
            'formErrors' => [],
        ]);
    }

    #[Route('/groups/{id}', name: 'app_community_group_show', requirements: ['id' => '\d+'])]
    public function showGroup(Group $group, ThreadRepository $threadRepo): Response
    {
        return $this->renderGroupPage($group, $threadRepo);
    }

    #[Route('/groups/{id}/update', name: 'app_community_group_update', methods: ['POST'])]
    public function updateGroup(
        Group $group,
        Request $request,
        EntityManagerInterface $em,
        ThreadRepository $threadRepo,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canManageGroup($group, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $formData = [
            'name' => $this->sanitizeSubmittedTitle((string) $request->request->get('name', '')),
            'description' => $this->sanitizeSubmittedBody((string) $request->request->get('description', '')),
            'is_private' => $request->request->getBoolean('is_private'),
        ];
        $formErrors = $this->validateGroupInput($formData['name'], $formData['description']);

        if ($formErrors !== []) {
            return $this->renderGroupPage($group, $threadRepo, [
                'groupEditData' => $formData,
                'groupEditErrors' => $formErrors,
                'openGroupEdit' => true,
            ]);
        }

        $moderated = $textModerationService->moderate($formData['description']);
        $group->setName($formData['name']);
        $group->setDescription($moderated['text']);
        $group->setIsPrivate($formData['is_private']);
        $em->flush();

        if ($moderated['changed']) {
            $this->addFlash('info', 'La description a ete legerement moderee avant enregistrement.');
        }

        $this->addFlash('success', 'Groupe mis a jour.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
    }

    #[Route('/groups/{id}/join', name: 'app_community_group_join', methods: ['POST'])]
    public function joinGroup(Group $group, EntityManagerInterface $em): Response
    {
        $user = $this->currentUser();
        $groupId = (int) ($group->getId() ?? 0);
        $userId = (int) ($user->getId() ?? 0);

        if ($group->getGroupAdmin()?->getId() === $userId || $this->groupRepository->isMember($groupId, $userId)) {
            $this->addFlash('info', 'Vous etes deja membre de ce groupe.');

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        if ($group->isPrivate() && !$this->isGranted('ROLE_ADMIN')) {
            if ($this->groupRepository->hasPendingRequest($groupId, $userId)) {
                $this->addFlash('info', 'Votre demande d\'acces est deja en attente.');
            } else {
                $this->groupRepository->requestJoin($groupId, $userId);
                $this->addFlash('success', 'Demande d\'acces envoyee a l\'administrateur du groupe.');
            }

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        $member = new GroupMember();
        $member->setGroup($group);
        $member->setUser($user);
        $em->persist($member);
        $em->flush();

        $this->addFlash('success', 'Vous avez rejoint le groupe.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
    }

    #[Route('/groups/{id}/requests/cancel', name: 'app_community_group_request_cancel', methods: ['POST'])]
    public function cancelGroupJoinRequest(Group $group): Response
    {
        $user = $this->currentUser();
        $this->groupRepository->cancelPendingRequest((int) ($group->getId() ?? 0), (int) ($user->getId() ?? 0));
        $this->addFlash('success', 'Demande d\'acces annulee.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
    }

    #[Route('/groups/{groupId}/requests/{requestId}/approve', name: 'app_community_group_request_approve', methods: ['POST'])]
    public function approveGroupJoinRequest(int $groupId, int $requestId): Response
    {
        $group = $this->groupRepository->find($groupId);

        if (!$group instanceof Group) {
            throw $this->createNotFoundException();
        }

        if (!$this->canManageGroup($group, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $approved = $this->groupRepository->approveRequest($groupId, $requestId);
        $this->addFlash($approved === null ? 'info' : 'success', $approved === null ? 'Cette demande n\'est plus en attente.' : 'Demande approuvee.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $groupId]);
    }

    #[Route('/groups/{groupId}/requests/{requestId}/reject', name: 'app_community_group_request_reject', methods: ['POST'])]
    public function rejectGroupJoinRequest(int $groupId, int $requestId): Response
    {
        $group = $this->groupRepository->find($groupId);

        if (!$group instanceof Group) {
            throw $this->createNotFoundException();
        }

        if (!$this->canManageGroup($group, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $this->groupRepository->rejectRequest($groupId, $requestId);
        $this->addFlash('success', 'Demande refusee.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $groupId]);
    }

    #[Route('/groups/{id}/leave', name: 'app_community_group_leave', methods: ['POST'])]
    public function leaveGroup(Group $group, EntityManagerInterface $em): Response
    {
        $user = $this->currentUser();

        if ($group->getGroupAdmin()?->getId() === $user->getId()) {
            $this->addFlash('danger', 'Le createur du groupe ne peut pas le quitter.');

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        $membership = $em->getRepository(GroupMember::class)->findOneBy(['group' => $group, 'user' => $user]);
        if ($membership instanceof GroupMember) {
            $em->remove($membership);
            $em->flush();
            $this->addFlash('success', 'Vous avez quitte le groupe.');
        }

        return $this->redirectToRoute('app_community_groups');
    }

    #[Route('/groups/{id}/kick/{memberId}', name: 'app_community_group_kick', methods: ['POST'])]
    public function kickMember(Group $group, int $memberId, EntityManagerInterface $em): Response
    {
        if (!$this->canManageGroup($group, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        if ($group->getGroupAdmin()?->getId() === $memberId) {
            $this->addFlash('danger', 'Vous ne pouvez pas vous exclure vous-meme.');
            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        $membership = $em->getRepository(GroupMember::class)->findOneBy(['group' => $group, 'user' => $memberId]);
        if ($membership instanceof GroupMember) {
            $em->remove($membership);
            $em->flush();
            $this->addFlash('success', 'Membre exclu du groupe.');
        }

        return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
    }

    #[Route('/groups/{id}/delete', name: 'app_community_group_delete', methods: ['POST'])]
    public function deleteGroup(Group $group, EntityManagerInterface $em): Response
    {
        if (!$this->canManageGroup($group, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($group);
        $em->flush();
        $this->addFlash('success', 'Groupe supprime.');

        return $this->redirectToRoute('app_community_groups');
    }

    // ========== THREADS ==========
    #[Route('/groups/{id}/threads/new', name: 'app_community_thread_new', methods: ['POST'])]
    public function newThread(
        Group $group,
        Request $request,
        EntityManagerInterface $em,
        ThreadRepository $threadRepo,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canParticipateInGroup($group, $this->currentUser())) {
            $this->addFlash('warning', 'Rejoignez ce groupe avant de publier une discussion.');

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        $title = $this->sanitizeSubmittedTitle((string) $request->request->get('title', ''));
        $content = $this->sanitizeSubmittedBody((string) $request->request->get('content', ''));
        $formErrors = $this->validateThreadInput($title, $content);

        if ($formErrors !== []) {
            return $this->renderGroupPage($group, $threadRepo, [
                'threadFormData' => [
                    'title' => $title,
                    'content' => $content,
                ],
                'threadFormErrors' => $formErrors,
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $thread = new Thread();
        $thread->setGroup($group);
        $thread->setUser($this->currentUser());
        $thread->setTitle($title);
        $thread->setContent($moderated['text']);

        $em->persist($thread);
        $em->flush();

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le texte a ete legerement modere avant publication.');
        }

        $this->addFlash('success', 'Discussion créée !');
        return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
    }

    #[Route('/threads/{id}', name: 'app_community_thread_show')]
    public function showThread(Thread $thread, Request $request, CommentRepository $commentRepository): Response
    {
        $group = $thread->getGroup();
        if (!$group instanceof Group) {
            throw $this->createNotFoundException();
        }

        if (!$this->canAccessGroup($group, $this->currentUser())) {
            $this->addFlash('warning', 'Cette discussion appartient a un groupe prive que vous ne pouvez pas consulter.');

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        return $this->renderThreadPage($thread, $request, $commentRepository);
    }

    #[Route('/threads/{id}/comment', name: 'app_community_thread_comment', methods: ['POST'])]
    public function commentThread(
        Thread $thread,
        Request $request,
        EntityManagerInterface $em,
        CommentRepository $commentRepository,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canParticipateInGroup($thread->getGroup(), $this->currentUser())) {
            $this->addFlash('warning', 'Rejoignez ce groupe avant de commenter.');

            return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
        }

        $content = $this->sanitizeSubmittedBody((string) $request->request->get('content', ''));
        $formErrors = $this->validateCommentInput($content);
        if ($formErrors !== []) {
            return $this->renderThreadPage($thread, $request, $commentRepository, [
                'commentFormData' => [
                    'content' => $content,
                ],
                'commentFormErrors' => $formErrors,
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $comment = new Comment();
        $comment->setThread($thread);
        $comment->setUser($this->currentUser());
        $comment->setContent($moderated['text']);

        $em->persist($comment);
        $em->flush();

        $this->resetThreadAiState($request, (int) $thread->getId());

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le commentaire a ete legerement modere avant publication.');
        }

        return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
    }

    #[Route('/threads/{id}/update', name: 'app_community_thread_update', methods: ['POST'])]
    public function updateThread(
        Thread $thread,
        Request $request,
        EntityManagerInterface $em,
        CommentRepository $commentRepository,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canManageThread($thread, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $title = $this->sanitizeSubmittedTitle((string) $request->request->get('title', ''));
        $content = $this->sanitizeSubmittedBody((string) $request->request->get('content', ''));

        $formErrors = $this->validateThreadInput($title, $content);
        if ($formErrors !== []) {
            return $this->renderThreadPage($thread, $request, $commentRepository, [
                'threadEditData' => [
                    'title' => $title,
                    'content' => $content,
                ],
                'threadEditErrors' => $formErrors,
                'openThreadEdit' => true,
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $thread->setTitle($title);
        $thread->setContent($moderated['text']);
        $em->flush();
        $this->resetThreadAiState($request, (int) $thread->getId());

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le texte a ete legerement modere avant enregistrement.');
        }

        $this->addFlash('success', 'Discussion mise a jour.');

        return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
    }

    #[Route('/threads/{id}/delete', name: 'app_community_thread_delete', methods: ['POST'])]
    public function deleteThread(Thread $thread, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->canManageThread($thread, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $groupId = $thread->getGroup()?->getId();
        $threadId = (int) $thread->getId();
        $em->remove($thread);
        $em->flush();
        $this->resetThreadAiState($request, $threadId);
        $this->addFlash('success', 'Discussion supprimee.');

        return $this->redirectToRoute('app_community_group_show', ['id' => $groupId]);
    }

    #[Route('/comments/{id}/update', name: 'app_community_comment_update', methods: ['POST'])]
    public function updateComment(
        Comment $comment,
        Request $request,
        EntityManagerInterface $em,
        CommentRepository $commentRepository,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canManageComment($comment, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $content = $this->sanitizeSubmittedBody((string) $request->request->get('content', ''));
        $formErrors = $this->validateCommentInput($content);
        if ($formErrors !== []) {
            return $this->renderThreadPage($comment->getThread(), $request, $commentRepository, [
                'commentEditTargetId' => (int) $comment->getId(),
                'commentEditData' => [
                    'content' => $content,
                ],
                'commentEditErrors' => $formErrors,
            ]);
        }

        $moderated = $textModerationService->moderate($content);
        $comment->setContent($moderated['text']);
        $em->flush();
        $this->resetThreadAiState($request, (int) $comment->getThread()?->getId());

        if ($moderated['changed']) {
            $this->addFlash('info', 'Le commentaire a ete legerement modere avant enregistrement.');
        }

        $this->addFlash('success', 'Commentaire mis a jour.');

        return $this->redirectToRoute('app_community_thread_show', ['id' => $comment->getThread()?->getId()]);
    }

    #[Route('/comments/{id}/delete', name: 'app_community_comment_delete', methods: ['POST'])]
    public function deleteComment(Comment $comment, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->canManageComment($comment, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $threadId = (int) $comment->getThread()?->getId();
        $em->remove($comment);
        $em->flush();
        $this->resetThreadAiState($request, $threadId);
        $this->addFlash('success', 'Commentaire supprime.');

        return $this->redirectToRoute('app_community_thread_show', ['id' => $threadId]);
    }

    #[Route('/threads/{id}/summary', name: 'app_community_thread_summary', methods: ['POST'])]
    public function summarizeThread(
        Thread $thread,
        Request $request,
        CommentRepository $commentRepository,
        CommunityAiService $communityAiService,
    ): Response
    {
        if (!$this->canAccessGroup($thread->getGroup(), $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $summary = $communityAiService->summarizeThread($thread, $commentRepository->findByThreadChronological($thread));
        $request->getSession()->set($this->threadSummaryKey((int) $thread->getId()), $summary);
        $this->addCommunityAiFlash($summary, 'Resume de discussion genere via Groq.', 'Resume de discussion mis a jour en mode local.');

        return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
    }

    #[Route('/threads/{id}/reply-suggestions', name: 'app_community_thread_reply_suggestions', methods: ['POST'])]
    public function threadReplySuggestions(
        Thread $thread,
        Request $request,
        CommentRepository $commentRepository,
        CommunityAiService $communityAiService,
    ): Response
    {
        if (!$this->canAccessGroup($thread->getGroup(), $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $suggestions = $communityAiService->suggestThreadReplies($thread, $commentRepository->findByThreadChronological($thread));
        $request->getSession()->set($this->threadSuggestionsKey((int) $thread->getId()), $suggestions);
        $this->addCommunityAiFlash($suggestions, 'Suggestions de reponse generees via Groq.', 'Suggestions de reponse generees en mode local.');

        return $this->redirectToRoute('app_community_thread_show', ['id' => $thread->getId()]);
    }

    // ========== EVENTS ==========
    #[Route('/events', name: 'app_community_events')]
    public function events(EventRepository $repo, Request $request): Response
    {
        $events = $repo->findUpcoming(50);
        $search = trim((string) $request->query->get('q', ''));
        $sort = trim((string) $request->query->get('sort', 'upcoming'));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $events = array_values(array_filter($events, static function (Event $event) use ($needle): bool {
                return str_contains(mb_strtolower(trim((string) $event->getTitle())), $needle)
                    || str_contains(mb_strtolower(trim((string) $event->getDescription())), $needle);
            }));
        }

        usort($events, static function (Event $left, Event $right) use ($sort): int {
            return match ($sort) {
                'recent' => ($right->getEventDate()?->getTimestamp() ?? 0) <=> ($left->getEventDate()?->getTimestamp() ?? 0),
                'capacity' => ($right->getCapacity() <=> $left->getCapacity())
                    ?: (($left->getEventDate()?->getTimestamp() ?? 0) <=> ($right->getEventDate()?->getTimestamp() ?? 0)),
                default => ($left->getEventDate()?->getTimestamp() ?? 0) <=> ($right->getEventDate()?->getTimestamp() ?? 0),
            };
        });

        return $this->render('front/community/events.html.twig', [
            'events' => $events,
            'search' => $search,
            'sort' => $sort,
        ]);
    }

    #[Route('/events/new', name: 'app_community_event_new', methods: ['GET', 'POST'])]
    public function newEvent(
        Request $request,
        EntityManagerInterface $em,
        CommunityTextModerationService $textModerationService,
        ValidatorInterface $validator,
    ): Response
    {
        if ($request->isMethod('POST')) {
            $formData = [
                'title' => $this->sanitizeSubmittedTitle((string) $request->request->get('title', '')),
                'description' => $this->sanitizeSubmittedBody((string) $request->request->get('description', '')),
                'event_date' => trim((string) $request->request->get('event_date', '')),
                'capacity' => trim((string) $request->request->get('capacity', '')),
            ];
            $eventDate = $this->normalizeEventDate($formData['event_date']);
            $formErrors = $this->validateEventInput($formData['title'], $formData['description'], $eventDate, $formData['capacity']);

            if ($formErrors !== []) {
                return $this->render('front/community/event_form.html.twig', [
                    'formData' => $formData,
                    'formErrors' => $formErrors,
                ]);
            }

            $moderated = $textModerationService->moderate($formData['description']);
            $event = new Event();
            $event->setTitle($formData['title']);
            $event->setDescription($moderated['text']);
            $event->setEventDate($eventDate);
            $event->setCapacity((int) $formData['capacity']);
            $event->setCreatedBy($this->currentUser());

            $errors = $validator->validate($event);
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
                return $this->render('front/community/event_form.html.twig');
            }

            $em->persist($event);
            $participant = new EventParticipant();
            $participant->setEvent($event);
            $participant->setUser($this->currentUser());
            $em->persist($participant);
            $em->flush();

            if ($moderated['changed']) {
                $this->addFlash('info', 'La description a ete legerement moderee avant creation.');
            }

            $this->addFlash('success', 'Événement créé !');

            return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
        }

        return $this->render('front/community/event_form.html.twig', [
            'formData' => [
                'title' => '',
                'description' => '',
                'event_date' => '',
                'capacity' => '1',
            ],
            'formErrors' => [],
        ]);
    }

    #[Route('/events/{id}', name: 'app_community_event_show', requirements: ['id' => '\d+'])]
    public function showEvent(
        Event $event,
        Request $request,
        CommunityWeatherService $communityWeatherService,
    ): Response
    {
        return $this->renderEventPage($event, $request, $communityWeatherService);
    }

    #[Route('/events/{id}/join', name: 'app_community_event_join', methods: ['POST'])]
    public function joinEvent(
        Event $event,
        EntityManagerInterface $em,
        CommunityTicketService $communityTicketService,
        EmailService $emailService,
    ): Response
    {
        $user = $this->currentUser();

        if ($event->hasParticipant($user)) {
            $this->addFlash('info', 'Vous etes deja inscrit a cet evenement.');

            return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
        }

        if (!$event->hasCapacity()) {
            $this->addFlash('danger', 'L\'événement est complet.');

            return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
        }

        $participant = new EventParticipant();
        $participant->setEvent($event);
        $participant->setUser($user);
        $em->persist($participant);
        $em->flush();

        $ticket = $communityTicketService->buildTicket($event, $user);
        $qrPng = null;

        try {
            $qrPng = $communityTicketService->renderQrPng($ticket['payload']);
        } catch (Throwable) {
        }

        try {
            $emailService->sendCommunityEventTicket(
                (string) $user->getEmail(),
                (string) $user->getFirstname(),
                $event,
                $ticket,
                $qrPng,
                $this->generateUrl('app_community_event_show', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            );

            $this->addFlash('success', 'Inscription confirmee. Le ticket QR a ete envoye par email.');
        } catch (Throwable $exception) {
            $this->addFlash('warning', 'Inscription confirmee, mais l envoi du ticket par email a echoue.');
        }

        return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{id}/leave', name: 'app_community_event_leave', methods: ['POST'])]
    public function leaveEvent(Event $event, EntityManagerInterface $em): Response
    {
        $user = $this->currentUser();

        if ($event->getCreatedBy()?->getId() === $user->getId()) {
            $this->addFlash('danger', 'Le createur de l\'evenement ne peut pas le quitter.');

            return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
        }

        $participant = $event->getParticipantForUser($user);
        if ($participant instanceof EventParticipant) {
            $em->remove($participant);
            $em->flush();
            $this->addFlash('success', 'Vous avez quitte l\'evenement.');
        }

        return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{id}/update', name: 'app_community_event_update', methods: ['POST'])]
    public function updateEvent(
        Event $event,
        Request $request,
        EntityManagerInterface $em,
        CommunityWeatherService $communityWeatherService,
        CommunityTextModerationService $textModerationService,
    ): Response
    {
        if (!$this->canManageEvent($event, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $formData = [
            'title' => $this->sanitizeSubmittedTitle((string) $request->request->get('title', '')),
            'description' => $this->sanitizeSubmittedBody((string) $request->request->get('description', '')),
            'event_date' => trim((string) $request->request->get('event_date', '')),
            'capacity' => trim((string) $request->request->get('capacity', '')),
        ];
        $eventDate = $this->normalizeEventDate($formData['event_date']);
        $formErrors = $this->validateEventInput($formData['title'], $formData['description'], $eventDate, $formData['capacity']);

        if ($formErrors !== []) {
            return $this->renderEventPage($event, $request, $communityWeatherService, [
                'eventEditData' => $formData,
                'eventEditErrors' => $formErrors,
                'openEventEdit' => true,
            ]);
        }

        $moderated = $textModerationService->moderate($formData['description']);
        $event->setTitle($formData['title']);
        $event->setDescription($moderated['text']);
        $event->setEventDate($eventDate);
        $event->setCapacity((int) $formData['capacity']);
        $em->flush();

        if ($moderated['changed']) {
            $this->addFlash('info', 'La description a ete legerement moderee avant enregistrement.');
        }

        $this->addFlash('success', 'Evenement mis a jour.');

        return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{id}/delete', name: 'app_community_event_delete', methods: ['POST'])]
    public function deleteEvent(Event $event, EntityManagerInterface $em): Response
    {
        if (!$this->canManageEvent($event, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($event);
        $em->flush();
        $this->addFlash('success', 'Evenement supprime.');

        return $this->redirectToRoute('app_community_events');
    }

    #[Route('/events/{id}/ai', name: 'app_community_event_ai', methods: ['POST'])]
    public function eventAi(
        Event $event,
        Request $request,
        CommunityAiService $communityAiService,
    ): Response
    {
        $mode = trim((string) $request->request->get('mode', 'summary'));
        $result = $communityAiService->generateEventText($event, $mode);
        $request->getSession()->set($this->eventAiKey((int) $event->getId()), $result);
        $request->getSession()->set($this->eventAiModeKey((int) $event->getId()), $mode);
        $this->addCommunityAiFlash($result, 'Assistant evenement mis a jour via Groq.', 'Assistant evenement mis a jour en mode local.');

        return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{id}/report', name: 'app_community_event_report', methods: ['GET'])]
    public function exportEventReport(
        Event $event,
        CommunityAiService $communityAiService,
        CommunityEventPdfService $communityEventPdfService,
    ): Response
    {
        if (!$this->canManageEvent($event, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $participants = $this->sortedEventParticipants($event);
        $aiSections = [
            'summary' => $this->normalizeTextAiResult($communityAiService->generateEventText($event, 'summary')),
            'promo' => $this->normalizeTextAiResult($communityAiService->generateEventText($event, 'promo')),
            'checklist' => $this->normalizeTextAiResult($communityAiService->generateEventText($event, 'checklist')),
        ];

        $response = new Response($communityEventPdfService->render($event, $participants, $aiSections));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$this->eventReportFilename($event).'"');

        return $response;
    }

    #[Route('/events/{id}/tickets/validate', name: 'app_community_event_ticket_validate', methods: ['POST'])]
    public function validateEventTicket(
        Event $event,
        Request $request,
        CommunityTicketService $communityTicketService,
    ): Response
    {
        if (!$this->canManageEvent($event, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        try {
            $payload = trim((string) $request->request->get('payload', ''));
            $image = $request->files->get('ticket_image');

            if ($payload === '' && $image instanceof UploadedFile) {
                $payload = $communityTicketService->decodeUploadedImage($image);
            }

            $result = $communityTicketService->validatePayloadForEvent($payload, $event);
            $request->getSession()->set($this->eventTicketValidationKey((int) $event->getId()), [
                'valid' => $result['valid'],
                'message' => $result['valid'] ? 'Ticket valide.' : 'Ticket invalide ou modifie.',
                'payload' => $result['payload'],
                'user_id' => $result['parsed']['u'],
            ]);

            $this->addFlash($result['valid'] ? 'success' : 'danger', $result['valid'] ? 'Ticket valide.' : 'Ticket invalide.');
        } catch (Throwable $exception) {
            $request->getSession()->set($this->eventTicketValidationKey((int) $event->getId()), [
                'valid' => false,
                'message' => $exception->getMessage(),
                'payload' => trim((string) $request->request->get('payload', '')),
                'user_id' => null,
            ]);

            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_community_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{eventId}/participants/{userId}/remove', name: 'app_community_event_remove_participant', methods: ['POST'])]
    public function removeEventParticipant(int $eventId, int $userId, EntityManagerInterface $em, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($eventId);
        $user = $em->getRepository(User::class)->find($userId);

        if (!$event instanceof Event || !$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if (!$this->canManageEvent($event, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        if ($event->getCreatedBy()?->getId() === $user->getId()) {
            $this->addFlash('danger', 'Le createur ne peut pas etre retire de son evenement.');

            return $this->redirectToRoute('app_community_event_show', ['id' => $eventId]);
        }

        $participant = $event->getParticipantForUser($user);
        if ($participant instanceof EventParticipant) {
            $em->remove($participant);
            $em->flush();
            $this->addFlash('success', 'Participant retire de l\'evenement.');
        }

        return $this->redirectToRoute('app_community_event_show', ['id' => $eventId]);
    }

    private function renderGroupPage(Group $group, ThreadRepository $threadRepo, array $context = []): Response
    {
        $user = $this->currentUser();
        $groupId = (int) ($group->getId() ?? 0);
        $isOwner = $this->canManageGroup($group, $user);
        $isMember = $isOwner || $this->groupRepository->isMember($groupId, (int) $user->getId());
        $canViewContent = !$group->isPrivate() || $isMember || $this->isGranted('ROLE_ADMIN');
        $threads = $canViewContent ? $threadRepo->findByGroup($group) : [];

        return $this->render('front/community/group_show.html.twig', array_replace([
            'group' => $group,
            'threads' => $threads,
            'isOwner' => $isOwner,
            'isMember' => $isMember,
            'canViewContent' => $canViewContent,
            'canParticipate' => $isMember || $this->isGranted('ROLE_ADMIN'),
            'hasPendingRequest' => !$isOwner && !$isMember && $this->groupRepository->hasPendingRequest($groupId, (int) $user->getId()),
            'pendingRequests' => $isOwner ? $this->groupRepository->findPendingRequestsForGroup($groupId) : [],
            'threadFormData' => [
                'title' => '',
                'content' => '',
            ],
            'threadFormErrors' => [],
            'groupEditData' => [
                'name' => (string) $group->getName(),
                'description' => (string) $group->getDescription(),
                'is_private' => $group->isPrivate(),
            ],
            'groupEditErrors' => [],
            'openGroupEdit' => false,
        ], $context));
    }

    private function renderPostsPage(PostRepository $repo, Request $request, array $context = []): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $sort = trim((string) $request->query->get('sort', 'newest'));
        $posts = $repo->findFeed();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $posts = array_values(array_filter($posts, static function (Post $post) use ($needle): bool {
                $haystack = mb_strtolower(trim((string) $post->getUser()?->getFullName().' '.(string) $post->getContent()));

                return str_contains($haystack, $needle);
            }));
        }

        usort($posts, static function (Post $left, Post $right) use ($sort): int {
            return match ($sort) {
                'oldest' => ($left->getCreatedAt()?->getTimestamp() ?? 0) <=> ($right->getCreatedAt()?->getTimestamp() ?? 0),
                'reactions' => ($right->getReactionsCount() <=> $left->getReactionsCount())
                    ?: (($right->getCreatedAt()?->getTimestamp() ?? 0) <=> ($left->getCreatedAt()?->getTimestamp() ?? 0)),
                default => ($right->getCreatedAt()?->getTimestamp() ?? 0) <=> ($left->getCreatedAt()?->getTimestamp() ?? 0),
            };
        });

        return $this->render('front/community/posts.html.twig', array_replace([
            'posts' => $posts,
            'search' => $search,
            'sort' => $sort,
            'reactionMeta' => self::REACTION_META,
            'translationLanguages' => self::TRANSLATION_LANGUAGES,
            'upcomingEvents' => $this->eventRepository->findUpcoming(3),
            'newPostData' => [
                'content' => '',
            ],
            'newPostErrors' => [],
            'openPostEditId' => null,
            'postEditData' => [
                'content' => '',
                'remove_image' => false,
            ],
            'postEditErrors' => [],
        ], $context));
    }

    private function renderThreadPage(Thread $thread, Request $request, CommentRepository $commentRepository, array $context = []): Response
    {
        $group = $thread->getGroup();
        if (!$group instanceof Group) {
            throw $this->createNotFoundException();
        }

        if (!$this->canAccessGroup($group, $this->currentUser())) {
            $this->addFlash('warning', 'Cette discussion appartient a un groupe prive que vous ne pouvez pas consulter.');

            return $this->redirectToRoute('app_community_group_show', ['id' => $group->getId()]);
        }

        return $this->render('front/community/thread_show.html.twig', array_replace([
            'thread' => $thread,
            'comments' => $commentRepository->findByThreadChronological($thread),
            'threadSummaryResult' => $this->normalizeTextAiResult($request->getSession()->get($this->threadSummaryKey((int) $thread->getId()))),
            'replySuggestionsResult' => $this->normalizeSuggestionsAiResult($request->getSession()->get($this->threadSuggestionsKey((int) $thread->getId()), [])),
            'canParticipate' => $this->canParticipateInGroup($group, $this->currentUser()),
            'threadEditData' => [
                'title' => (string) $thread->getTitle(),
                'content' => (string) $thread->getContent(),
            ],
            'threadEditErrors' => [],
            'openThreadEdit' => false,
            'commentFormData' => [
                'content' => '',
            ],
            'commentFormErrors' => [],
            'commentEditTargetId' => null,
            'commentEditData' => [
                'content' => '',
            ],
            'commentEditErrors' => [],
        ], $context));
    }

    private function renderEventPage(
        Event $event,
        Request $request,
        CommunityWeatherService $communityWeatherService,
        array $context = [],
    ): Response {
        $user = $this->currentUser();
        $participants = $this->sortedEventParticipants($event);

        return $this->render('front/community/event_show.html.twig', array_replace([
            'event' => $event,
            'participants' => $participants,
            'weather' => $communityWeatherService->forecastForEvent($event->getEventDate()),
            'isCreator' => $this->canManageEvent($event, $user),
            'isParticipant' => $event->hasParticipant($user),
            'isFull' => !$event->hasCapacity(),
            'eventAiResult' => $this->normalizeTextAiResult($request->getSession()->get($this->eventAiKey((int) $event->getId()))),
            'eventAiMode' => $request->getSession()->get($this->eventAiModeKey((int) $event->getId()), 'summary'),
            'ticketValidation' => $request->getSession()->get($this->eventTicketValidationKey((int) $event->getId())),
            'eventEditData' => [
                'title' => (string) $event->getTitle(),
                'description' => (string) $event->getDescription(),
                'event_date' => $event->getEventDate()?->format('Y-m-d\TH:i') ?? '',
                'capacity' => (string) max((int) $event->getCapacity(), 1),
            ],
            'eventEditErrors' => [],
            'openEventEdit' => false,
        ], $context));
    }

    private function normalizeTextAiResult(mixed $value): ?array
    {
        if (is_string($value)) {
            $content = trim($value);

            return $content === '' ? null : [
                'content' => $content,
                'used_provider' => false,
                'configured' => false,
                'provider' => 'Local',
                'model' => null,
                'source_label' => 'Mode local',
                'source_hint' => null,
                'error' => false,
                'error_message' => null,
            ];
        }

        if (!is_array($value)) {
            return null;
        }

        $content = trim((string) ($value['content'] ?? ''));
        $error = (bool) ($value['error'] ?? false);
        $errorMessage = trim((string) ($value['error_message'] ?? ''));

        if ($content === '' && $errorMessage === '') {
            return null;
        }

        $usedProvider = (bool) ($value['used_provider'] ?? false);
        $configured = (bool) ($value['configured'] ?? false);
        $model = trim((string) ($value['model'] ?? ''));
        $sourceHint = trim((string) ($value['source_hint'] ?? ''));

        return [
            'content' => $content,
            'used_provider' => $usedProvider,
            'configured' => $configured,
            'provider' => trim((string) ($value['provider'] ?? ($usedProvider ? 'Groq' : 'Local'))),
            'model' => $model !== '' ? $model : null,
            'source_label' => trim((string) ($value['source_label'] ?? ($error ? 'Groq indisponible' : ($usedProvider ? 'Groq' : ($configured ? 'Repli local' : 'Mode local'))))),
            'source_hint' => $sourceHint !== '' ? $sourceHint : null,
            'error' => $error,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
        ];
    }

    private function normalizeSuggestionsAiResult(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return [
                'items' => $this->normalizeSuggestionItems($value),
                'used_provider' => false,
                'configured' => false,
                'provider' => 'Local',
                'model' => null,
                'source_label' => 'Mode local',
                'source_hint' => null,
                'error' => false,
                'error_message' => null,
            ];
        }

        if (!is_array($value)) {
            return [
                'items' => [],
                'used_provider' => false,
                'configured' => false,
                'provider' => 'Local',
                'model' => null,
                'source_label' => 'Mode local',
                'source_hint' => null,
                'error' => false,
                'error_message' => null,
            ];
        }

        $usedProvider = (bool) ($value['used_provider'] ?? false);
        $configured = (bool) ($value['configured'] ?? false);
        $model = trim((string) ($value['model'] ?? ''));
        $sourceHint = trim((string) ($value['source_hint'] ?? ''));
        $error = (bool) ($value['error'] ?? false);
        $errorMessage = trim((string) ($value['error_message'] ?? ''));

        return [
            'items' => $this->normalizeSuggestionItems($value['items'] ?? []),
            'used_provider' => $usedProvider,
            'configured' => $configured,
            'provider' => trim((string) ($value['provider'] ?? ($usedProvider ? 'Groq' : 'Local'))),
            'model' => $model !== '' ? $model : null,
            'source_label' => trim((string) ($value['source_label'] ?? ($error ? 'Groq indisponible' : ($usedProvider ? 'Groq' : ($configured ? 'Repli local' : 'Mode local'))))),
            'source_hint' => $sourceHint !== '' ? $sourceHint : null,
            'error' => $error,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
        ];
    }

    /** @return EventParticipant[] */
    private function sortedEventParticipants(Event $event): array
    {
        $participants = $event->getParticipants()->toArray();
        usort($participants, static function (EventParticipant $left, EventParticipant $right): int {
            return strcmp(
                trim((string) $left->getUser()?->getFullName()),
                trim((string) $right->getUser()?->getFullName()),
            );
        });

        return $participants;
    }

    /** @param mixed $items
     *  @return string[]
     */
    private function normalizeSuggestionItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }

            $normalized[] = $text;
        }

        return array_values(array_unique($normalized));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function canManagePost(Post $post, User $user): bool
    {
        return $post->getUser()?->getId() === $user->getId() || $this->isGranted('ROLE_ADMIN');
    }

    private function canManageGroup(Group $group, User $user): bool
    {
        return $group->getGroupAdmin()?->getId() === $user->getId() || $this->isGranted('ROLE_ADMIN');
    }

    private function canAccessGroup(Group $group, User $user): bool
    {
        return !$group->isPrivate()
            || $group->getGroupAdmin()?->getId() === $user->getId()
            || $this->groupRepository->isMember((int) ($group->getId() ?? 0), (int) ($user->getId() ?? 0))
            || $this->isGranted('ROLE_ADMIN');
    }

    private function canManageThread(Thread $thread, User $user): bool
    {
        return $thread->getUser()?->getId() === $user->getId() || $this->isGranted('ROLE_ADMIN');
    }

    private function canParticipateInGroup(Group $group, User $user): bool
    {
        return $group->getGroupAdmin()?->getId() === $user->getId()
            || $this->groupRepository->isMember((int) ($group->getId() ?? 0), (int) ($user->getId() ?? 0))
            || $this->isGranted('ROLE_ADMIN');
    }

    private function canManageComment(Comment $comment, User $user): bool
    {
        return $comment->getUser()?->getId() === $user->getId() || $this->isGranted('ROLE_ADMIN');
    }

    private function canManageEvent(Event $event, User $user): bool
    {
        return $event->getCreatedBy()?->getId() === $user->getId() || $this->isGranted('ROLE_ADMIN');
    }

    private function reactionError(Request $request, string $message): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => false,
                'message' => $message,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('danger', $message);

        return $this->redirectToRoute('app_community_posts');
    }

    /** @param array<string, mixed> $result */
    private function addCommunityAiFlash(array $result, string $successMessage, string $localMessage): void
    {
        if ((bool) ($result['error'] ?? false)) {
            $message = trim((string) ($result['error_message'] ?? ''));
            $this->addFlash('danger', $message !== '' ? $message : 'Groq n a pas renvoye de resultat exploitable.');

            return;
        }

        if ((bool) ($result['used_provider'] ?? false)) {
            $this->addFlash('success', $successMessage);

            return;
        }

        $this->addFlash('info', $localMessage);
    }

    private function reactionStateResponse(Post $post, User $user): JsonResponse
    {
        $currentReaction = $post->getReactionTypeForUser($user);
        $meta = $currentReaction !== null ? (self::REACTION_META[$currentReaction] ?? null) : null;
        $caption = $post->getReactionsCount() > 0 ? $post->getReactionsCount().' reactions' : 'Pas encore de reaction';

        if ($meta !== null) {
            $caption .= ' · Vous avez choisi '.$meta['label'];
        }

        return new JsonResponse([
            'ok' => true,
            'totals' => $post->getReactionSummary(),
            'reactionsCount' => $post->getReactionsCount(),
            'myReaction' => $currentReaction,
            'currentLabel' => $meta['label'] ?? 'J\'aime',
            'currentEmoji' => $meta['emoji'] ?? (self::REACTION_META[PostReaction::TYPE_LIKE]['emoji'] ?? '👍'),
            'caption' => $caption,
        ]);
    }

    private function normalizeEventDate(string $raw): ?\DateTime
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d\TH:i', $raw);
        if ($date instanceof \DateTime) {
            return $date;
        }

        try {
            return new \DateTime($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function storePostUpload(?UploadedFile $file): ?string
    {
        if (!$file instanceof UploadedFile) {
            return null;
        }

        if (!$file->isValid()) {
            throw new \RuntimeException('Le televersement de l\'image a echoue.');
        }

        $mimeType = (string) ($file->getMimeType() ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \RuntimeException('Seules les images peuvent etre televersees.');
        }

        $directory = (string) $this->getParameter('kernel.project_dir').'/public/uploads/community/posts';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de preparer le dossier des images communautaires.');
        }

        $extension = $file->guessExtension() ?: ($file->getClientOriginalExtension() ?: 'bin');
        $name = uniqid('community_post_', true).'.'.$extension;
        $file->move($directory, $name);

        return '/uploads/community/posts/'.$name;
    }

    private function removeCommunityUpload(?string $publicPath): void
    {
        if (!is_string($publicPath) || $publicPath === '' || !str_starts_with($publicPath, '/uploads/community/posts/')) {
            return;
        }

        $absolutePath = (string) $this->getParameter('kernel.project_dir').'/public'.$publicPath;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function threadSummaryKey(int $threadId): string
    {
        return 'community.thread.summary.'.$threadId;
    }

    private function threadSuggestionsKey(int $threadId): string
    {
        return 'community.thread.suggestions.'.$threadId;
    }

    private function resetThreadAiState(Request $request, int $threadId): void
    {
        $session = $request->getSession();
        $session->remove($this->threadSummaryKey($threadId));
        $session->remove($this->threadSuggestionsKey($threadId));
    }

    private function validateGroupInput(string $name, string $description): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Le nom du groupe est requis.';
        } elseif (mb_strlen($name) < self::GROUP_NAME_MIN_LENGTH) {
            $errors['name'] = 'Le nom du groupe doit contenir au moins 3 caracteres.';
        } elseif (mb_strlen($name) > self::GROUP_NAME_MAX_LENGTH) {
            $errors['name'] = 'Le nom du groupe est trop long.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($name, 3)) {
            $errors['name'] = 'Le nom du groupe doit contenir de vrais caracteres utiles.';
        } elseif ($this->hasExcessiveRepeatedCharacters($name)) {
            $errors['name'] = 'Merci d eviter les suites de caracteres repetes dans le nom du groupe.';
        }

        if ($description === '') {
            $errors['description'] = 'La description du groupe est requise.';
        } elseif (mb_strlen($description) < self::GROUP_DESCRIPTION_MIN_LENGTH) {
            $errors['description'] = 'La description doit contenir au moins 15 caracteres utiles.';
        } elseif (mb_strlen($description) > self::GROUP_DESCRIPTION_MAX_LENGTH) {
            $errors['description'] = 'La description du groupe est trop longue.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($description, 10)) {
            $errors['description'] = 'La description doit contenir un vrai message exploitable.';
        } elseif ($this->hasExcessiveRepeatedCharacters($description)) {
            $errors['description'] = 'Merci d eviter les suites de caracteres repetes dans la description.';
        }

        return $errors;
    }

    private function validateThreadInput(string $title, string $content): array
    {
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Le titre de la discussion est requis.';
        } elseif (mb_strlen($title) < self::THREAD_TITLE_MIN_LENGTH) {
            $errors['title'] = 'Le titre doit contenir au moins 5 caracteres.';
        } elseif (mb_strlen($title) > self::THREAD_TITLE_MAX_LENGTH) {
            $errors['title'] = 'Le titre est trop long.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($title, 4)) {
            $errors['title'] = 'Le titre doit contenir plus que quelques symboles ou caracteres repetes.';
        } elseif ($this->hasExcessiveRepeatedCharacters($title)) {
            $errors['title'] = 'Merci d eviter les suites de caracteres repetes dans votre titre.';
        }

        if ($content === '') {
            $errors['content'] = 'Le contenu de la discussion est requis.';
        } elseif (mb_strlen($content) < self::THREAD_CONTENT_MIN_LENGTH) {
            $errors['content'] = 'Le contenu de la discussion doit contenir au moins 15 caracteres utiles.';
        } elseif (mb_strlen($content) > self::THREAD_CONTENT_MAX_LENGTH) {
            $errors['content'] = 'Le contenu de la discussion est trop long.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($content, 10)) {
            $errors['content'] = 'Le contenu de la discussion doit contenir un vrai message exploitable.';
        } elseif ($this->hasExcessiveRepeatedCharacters($content)) {
            $errors['content'] = 'Merci d eviter les suites de caracteres repetes dans votre discussion.';
        }

        return $errors;
    }

    private function validateCommentInput(string $content): array
    {
        $errors = [];

        if ($content === '') {
            $errors['content'] = 'Le commentaire ne peut pas etre vide.';
        } elseif (mb_strlen($content) < self::COMMENT_CONTENT_MIN_LENGTH) {
            $errors['content'] = 'Le commentaire est trop court pour etre utile.';
        } elseif (mb_strlen($content) > self::COMMENT_CONTENT_MAX_LENGTH) {
            $errors['content'] = 'Le commentaire est trop long.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($content, 3)) {
            $errors['content'] = 'Le commentaire doit contenir plus que quelques symboles ou caracteres repetes.';
        } elseif ($this->hasExcessiveRepeatedCharacters($content)) {
            $errors['content'] = 'Merci d eviter les suites de caracteres repetes dans votre commentaire.';
        }

        return $errors;
    }

    private function validateEventInput(string $title, string $description, ?\DateTime $eventDate, string $capacity): array
    {
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Le titre de l evenement est requis.';
        } elseif (mb_strlen($title) < self::EVENT_TITLE_MIN_LENGTH) {
            $errors['title'] = 'Le titre doit contenir au moins 5 caracteres.';
        } elseif (mb_strlen($title) > self::EVENT_TITLE_MAX_LENGTH) {
            $errors['title'] = 'Le titre de l evenement est trop long.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($title, 4)) {
            $errors['title'] = 'Le titre doit contenir plus que quelques symboles ou caracteres repetes.';
        } elseif ($this->hasExcessiveRepeatedCharacters($title)) {
            $errors['title'] = 'Merci d eviter les suites de caracteres repetes dans le titre.';
        }

        if ($description === '') {
            $errors['description'] = 'La description de l evenement est requise.';
        } elseif (mb_strlen($description) < self::EVENT_DESCRIPTION_MIN_LENGTH) {
            $errors['description'] = 'La description doit contenir au moins 15 caracteres utiles.';
        } elseif (mb_strlen($description) > self::EVENT_DESCRIPTION_MAX_LENGTH) {
            $errors['description'] = 'La description de l evenement est trop longue.';
        } elseif (!$this->hasEnoughMeaningfulCharacters($description, 10)) {
            $errors['description'] = 'La description doit contenir un vrai message exploitable.';
        } elseif ($this->hasExcessiveRepeatedCharacters($description)) {
            $errors['description'] = 'Merci d eviter les suites de caracteres repetes dans la description.';
        }

        if (!$eventDate instanceof \DateTime) {
            $errors['event_date'] = 'La date et l heure de l evenement sont requises.';
        } else {
            $now = new \DateTimeImmutable('now');
            if ($eventDate->getTimestamp() <= $now->getTimestamp()) {
                $errors['event_date'] = 'Choisissez une date future pour cet evenement.';
            }
        }

        if ($capacity === '') {
            $errors['capacity'] = 'La capacite est requise.';
        } elseif (filter_var($capacity, FILTER_VALIDATE_INT) === false) {
            $errors['capacity'] = 'La capacite doit etre un nombre entier.';
        } else {
            $capacityValue = (int) $capacity;

            if ($capacityValue < 1) {
                $errors['capacity'] = 'La capacite doit etre au moins de 1 participant.';
            } elseif ($capacityValue > self::EVENT_CAPACITY_MAX) {
                $errors['capacity'] = 'La capacite est trop elevee pour cet evenement.';
            }
        }

        return $errors;
    }

    private function sanitizeSubmittedTitle(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function sanitizeSubmittedBody(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[ \t]+/u', ' ', $value);
        $value = (string) preg_replace('/\n{3,}/u', "\n\n", $value);

        return trim($value);
    }

    private function hasEnoughMeaningfulCharacters(string $value, int $minimum): bool
    {
        preg_match_all('/[\p{L}\p{N}]/u', $value, $matches);

        return count($matches[0] ?? []) >= $minimum;
    }

    private function hasExcessiveRepeatedCharacters(string $value): bool
    {
        return preg_match('/(.)\1{7,}/u', $value) === 1;
    }

    private function eventAiKey(int $eventId): string
    {
        return 'community.event.ai.'.$eventId;
    }

    private function eventAiModeKey(int $eventId): string
    {
        return 'community.event.ai.mode.'.$eventId;
    }

    private function eventTicketValidationKey(int $eventId): string
    {
        return 'community.event.ticket.validation.'.$eventId;
    }

    private function eventReportFilename(Event $event): string
    {
        $slug = mb_strtolower(trim((string) $event->getTitle()));
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim($slug, '-');

        return sprintf('event-report-%d-%s.pdf', (int) $event->getId(), $slug !== '' ? $slug : 'community');
    }
}
