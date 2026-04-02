import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useAuth } from '@/context/AuthContext';
import { API_BASE_URL } from '@/lib/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Conversation = {
  id: number;
  deck_id: number | null;
  deck?: {
    id: number;
    name: string;
    format: string | null;
  } | null;
  title: string | null;
  updated_at: string;
};

type ProposalCard = {
  card_id: number;
  name: string | null;
  type_line: string | null;
  mana_cost: string | null;
  image_uri: string | null;
  quantity: number;
  owned_quantity: number;
  role: string | null;
  reason: string | null;
};

type DeckProposal = {
  proposal_type?: 'deck' | 'changes';
  deck_name: string;
  format: string | null;
  strategy_summary: string | null;
  cards: ProposalCard[];
  added_cards?: ProposalCard[];
  removed_cards?: ProposalCard[];
  buy_cards?: ProposalCard[];
  draft_deck_id?: number | null;
  validation_message?: string | null;
};

type Message = {
  id: number;
  role: 'user' | 'assistant' | 'tool';
  content: string | null;
  metadata: DeckProposal | null;
  created_at: string;
};

type BuilderOption = {
  label: string;
  value: string;
};

type SearchableCard = {
  id?: number;
  scryfall_id: string;
  name: string;
  set_code: string;
  set_name: string;
  collector_number: string;
  rarity: string;
  mana_cost: string | null;
  type_line: string;
  image_uri: string | null;
  color_identity?: string[];
};

const BUILD_FORMAT_OPTIONS: BuilderOption[] = [
  { label: 'Commander', value: 'commander' },
  { label: 'Brawl', value: 'brawl' },
  { label: 'Modern', value: 'modern' },
  { label: 'Standard', value: 'standard' },
  { label: 'Pioneer', value: 'pioneer' },
  { label: 'Casual', value: 'casual' },
];

const BUILD_PLAYSTYLE_OPTIONS: BuilderOption[] = [
  { label: 'Aggro', value: 'aggro' },
  { label: 'Midrange', value: 'midrange' },
  { label: 'Control', value: 'control' },
  { label: 'Combo', value: 'combo' },
  { label: 'Ramp', value: 'ramp' },
  { label: 'Tokens', value: 'tokens' },
];

const BUILD_BUDGET_OPTIONS: BuilderOption[] = [
  { label: 'Use my collection', value: 'use only cards I own' },
  { label: 'Under $50', value: 'under $50' },
  { label: 'Under $100', value: 'under $100' },
  { label: 'No budget', value: 'no budget' },
  { label: 'Custom', value: 'custom' },
];

const COMMANDER_FORMATS = new Set(['commander', 'brawl']);
const BUILDER_COLOR_OPTIONS = ['W', 'U', 'B', 'R', 'G', 'C'] as const;
type BuilderColor = typeof BUILDER_COLOR_OPTIONS[number];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function timeAgo(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function titleCase(value: string): string {
  return value
    .split(/[\s-]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

function formatColorLabel(color: BuilderColor): string {
  return {
    W: 'White',
    U: 'Blue',
    B: 'Black',
    R: 'Red',
    G: 'Green',
    C: 'Colorless',
  }[color];
}

function joinLabels(labels: string[]): string {
  if (labels.length <= 1) return labels[0] ?? '';
  if (labels.length === 2) return `${labels[0]} and ${labels[1]}`;
  return `${labels.slice(0, -1).join(', ')}, and ${labels[labels.length - 1]}`;
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function ProposalBubble({
  proposal,
  proposalMessageId,
  onCreateDeck,
  onValidateDraft,
  onDiscardDraft,
  creating,
  onCardPress,
}: {
  proposal: DeckProposal;
  proposalMessageId: number;
  onCreateDeck: (proposal: DeckProposal, messageId: number) => void;
  onValidateDraft: (draftId: number) => void;
  onDiscardDraft: (draftId: number) => void;
  creating: boolean;
  onCardPress: (card: ProposalCard) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const addedCards = proposal.added_cards ?? [];
  const removedCards = proposal.removed_cards ?? [];
  const buyCards = proposal.buy_cards ?? [];
  const isChangeProposal = proposal.proposal_type === 'changes';
  const hasValidationIssues = Boolean(proposal.validation_message);
  const hasDiffView = isChangeProposal || addedCards.length > 0 || removedCards.length > 0 || buyCards.length > 0;
  const sortedCards = [...proposal.cards].sort((a, b) => {
    if (a.role === 'commander') return -1;
    if (b.role === 'commander') return 1;
    return 0;
  });
  const visibleCards = expanded ? sortedCards : sortedCards.slice(0, 6);

  function renderCardRow(card: ProposalCard, kind: 'default' | 'added' | 'removed' = 'default') {
    return (
      <TouchableOpacity
        key={`${kind}-${card.card_id != null ? String(card.card_id) : card.name}`}
        style={styles.proposalCardRow}
        onPress={() => onCardPress(card)}
        activeOpacity={0.7}
      >
        {card.image_uri ? (
          <Image source={{ uri: card.image_uri }} style={styles.proposalCardThumb} resizeMode="cover" />
        ) : (
          <View style={[styles.proposalCardThumb, styles.proposalCardThumbPlaceholder]}>
            <Ionicons name="image-outline" size={14} color="#444" />
          </View>
        )}
        <View style={styles.proposalCardLeft}>
          <Text style={styles.proposalCardName}>{card.name ?? `Card #${card.card_id}`}</Text>
          <Text style={styles.proposalCardMeta}>
            {card.type_line ?? ''}
            {card.mana_cost ? `  ${card.mana_cost}` : ''}
          </Text>
          {card.reason ? <Text style={styles.proposalCardReason}>{card.reason}</Text> : null}
        </View>
        <View style={styles.proposalCardRight}>
          <Text
            style={[
              styles.proposalCardQty,
              kind === 'added' ? styles.deltaAddedText : null,
              kind === 'removed' ? styles.deltaRemovedText : null,
            ]}
          >
            {kind === 'added' ? '+' : kind === 'removed' ? '-' : '×'}
            {card.quantity}
          </Text>
          <Text style={[styles.proposalCardOwned, card.owned_quantity > 0 ? styles.ownedYes : styles.ownedNo]}>
            {card.owned_quantity > 0 ? `own ${card.owned_quantity}` : 'not owned'}
          </Text>
        </View>
      </TouchableOpacity>
    );
  }

  return (
    <View style={styles.proposalCard}>
      <View style={styles.proposalHeader}>
        <Ionicons name="sparkles" size={16} color="#ffd7a2" style={{ marginRight: 6 }} />
        <Text style={styles.proposalTitle}>{proposal.deck_name}</Text>
      </View>
      {proposal.format ? <Text style={styles.proposalMeta}>{proposal.format.toUpperCase()}</Text> : null}
      {proposal.strategy_summary ? (
        <Text style={styles.proposalStrategy}>{proposal.strategy_summary}</Text>
      ) : null}
      {proposal.validation_message ? (
        <Text style={styles.proposalWarning}>
          {proposal.draft_deck_id != null ? 'Draft saved with issues.' : 'Proposal has issues and was not saved.'} {proposal.validation_message}
        </Text>
      ) : null}

      <View style={styles.proposalDivider} />

      {hasDiffView ? (
        <>
          {addedCards.length > 0 ? (
            <View style={styles.changeSection}>
              <View style={styles.changeSectionHeader}>
                <Ionicons name="add-circle" size={16} color="#4ade80" />
                <Text style={styles.changeSectionTitle}>Added</Text>
              </View>
              {addedCards.map((card) => renderCardRow(card, 'added'))}
            </View>
          ) : null}
          {removedCards.length > 0 ? (
            <View style={styles.changeSection}>
              <View style={styles.changeSectionHeader}>
                <Ionicons name="remove-circle" size={16} color="#f87171" />
                <Text style={styles.changeSectionTitle}>Removed</Text>
              </View>
              {removedCards.map((card) => renderCardRow(card, 'removed'))}
            </View>
          ) : null}
          {buyCards.length > 0 ? (
            <View style={styles.changeSection}>
              <View style={styles.changeSectionHeader}>
                <Ionicons name="cart" size={16} color="#60a5fa" />
                <Text style={styles.changeSectionTitle}>Worth Buying</Text>
              </View>
              {buyCards.map((card) => renderCardRow(card, 'added'))}
            </View>
          ) : null}
        </>
      ) : (
        visibleCards.map((card) => renderCardRow(card))
      )}

      {!hasDiffView && proposal.cards.length > 6 ? (
        <TouchableOpacity onPress={() => setExpanded((v) => !v)} style={styles.showMoreBtn}>
          <Text style={styles.showMoreText}>
            {expanded ? 'Show less' : `Show all ${proposal.cards.length} cards`}
          </Text>
        </TouchableOpacity>
      ) : null}

      {isChangeProposal ? null : proposal.draft_deck_id != null ? (
        <View style={styles.draftActionRow}>
          {hasValidationIssues ? null : (
            <TouchableOpacity
              style={[styles.validateDeckBtn, creating && styles.buttonDisabled]}
              onPress={() => onValidateDraft(proposal.draft_deck_id!)}
              disabled={creating}
            >
              {creating ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <>
                  <Ionicons name="checkmark-circle" size={16} color="#fff" style={{ marginRight: 6 }} />
                  <Text style={styles.createDeckBtnText}>Save to My Decks</Text>
                </>
              )}
            </TouchableOpacity>
          )}
          <TouchableOpacity
            style={[
              hasValidationIssues ? styles.validateDeckBtn : styles.discardDeckBtn,
              creating && styles.buttonDisabled,
            ]}
            onPress={() =>
              hasValidationIssues
                ? Alert.alert('Draft saved', 'This draft is already in My Decks. Open it from the Decks tab to fix the remaining issues.')
                : onDiscardDraft(proposal.draft_deck_id!)
            }
            disabled={creating}
          >
            <Ionicons
              name={hasValidationIssues ? 'albums-outline' : 'trash-outline'}
              size={16}
              color={hasValidationIssues ? '#fff' : '#f87171'}
              style={{ marginRight: 6 }}
            />
            <Text style={hasValidationIssues ? styles.createDeckBtnText : styles.discardDeckBtnText}>
              {hasValidationIssues ? 'Open From Decks' : 'Discard'}
            </Text>
          </TouchableOpacity>
        </View>
      ) : (
        hasValidationIssues ? null : (
          <TouchableOpacity
            style={[styles.createDeckBtn, creating && styles.buttonDisabled]}
            onPress={() => onCreateDeck(proposal, proposalMessageId)}
            disabled={creating}
          >
            {creating ? (
              <ActivityIndicator color="#fff" size="small" />
            ) : (
              <>
                <Ionicons name="albums" size={16} color="#fff" style={{ marginRight: 6 }} />
                <Text style={styles.createDeckBtnText}>Create Deck</Text>
              </>
            )}
          </TouchableOpacity>
        )
      )}
    </View>
  );
}

function MessageBubble({
  message,
  onCreateDeck,
  onValidateDraft,
  onDiscardDraft,
  creating,
  onCardPress,
}: {
  message: Message;
  onCreateDeck: (proposal: DeckProposal, messageId: number) => void;
  onValidateDraft: (draftId: number) => void;
  onDiscardDraft: (draftId: number) => void;
  creating: boolean;
  onCardPress: (card: ProposalCard) => void;
}) {
  if (message.role === 'tool') return null;

  const isUser = message.role === 'user';

  return (
    <View style={[styles.bubbleWrapper, isUser ? styles.bubbleWrapperUser : styles.bubbleWrapperAssistant]}>
      {!isUser ? (
        <View style={styles.avatarDot}>
          <Ionicons name="sparkles" size={12} color="#ffd7a2" />
        </View>
      ) : null}
      <View style={[styles.bubble, isUser ? styles.bubbleUser : styles.bubbleAssistant]}>
        {message.content ? (
          <Text style={[styles.bubbleText, isUser ? styles.bubbleTextUser : styles.bubbleTextAssistant]}>
            {message.content}
          </Text>
        ) : null}
        {message.metadata ? (
          <ProposalBubble
            proposal={message.metadata}
            proposalMessageId={message.id}
            onCreateDeck={onCreateDeck}
            onValidateDraft={onValidateDraft}
            onDiscardDraft={onDiscardDraft}
            creating={creating}
            onCardPress={onCardPress}
          />
        ) : null}
      </View>
    </View>
  );
}

// ---------------------------------------------------------------------------
// Main screen
// ---------------------------------------------------------------------------

export default function AssistantScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ deckId?: string; deckName?: string }>();

  // Conversation list view
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [loadingList, setLoadingList] = useState(true);
  const [deletingConversationId, setDeletingConversationId] = useState<number | null>(null);

  // Active chat view
  const [activeConversation, setActiveConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loadingMessages, setLoadingMessages] = useState(false);

  // Sending
  const [inputText, setInputText] = useState('');
  const [sending, setSending] = useState(false);
  const [isStreaming, setIsStreaming] = useState(false);
  const [thinkingLabel, setThinkingLabel] = useState('VaultMage is thinking…');
  const [builderModalVisible, setBuilderModalVisible] = useState(false);
  const [builderFormat, setBuilderFormat] = useState(BUILD_FORMAT_OPTIONS[0].value);
  const [builderPlaystyle, setBuilderPlaystyle] = useState(BUILD_PLAYSTYLE_OPTIONS[0].value);
  const [builderBudget, setBuilderBudget] = useState(BUILD_BUDGET_OPTIONS[0].value);
  const [builderCustomBudget, setBuilderCustomBudget] = useState('');
  const [builderColors, setBuilderColors] = useState<BuilderColor[]>(['U']);
  const [commanderQuery, setCommanderQuery] = useState('');
  const [debouncedCommanderQuery, setDebouncedCommanderQuery] = useState('');
  const [commanderResults, setCommanderResults] = useState<SearchableCard[]>([]);
  const [commanderLoading, setCommanderLoading] = useState(false);
  const [selectedCommander, setSelectedCommander] = useState<SearchableCard | null>(null);

  // Deck creation
  const [creatingDeck, setCreatingDeck] = useState(false);
  const creatingDeckRef = useRef(false);

  // Card detail modal
  const [selectedProposalCard, setSelectedProposalCard] = useState<ProposalCard | null>(null);

  const flatListRef = useRef<FlatList>(null);
  const handledDeckParamRef = useRef<string | null>(null);
  const autoOpenedBuilderRef = useRef<number | null>(null);

  // -------------------------------------------------------------------------
  // Fetch conversations
  // -------------------------------------------------------------------------

  const fetchConversations = useCallback(async () => {
    try {
      const res = await fetch(`${API_BASE_URL}/api/chat/conversations`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      setConversations(await res.json());
    } catch {
      // network error
    }
  }, [token]);

  useEffect(() => {
    fetchConversations().finally(() => setLoadingList(false));
  }, [fetchConversations]);

  // -------------------------------------------------------------------------
  // Open / create conversation
  // -------------------------------------------------------------------------

  async function openConversation(conv: Conversation) {
    setActiveConversation(conv);
    setLoadingMessages(true);
    setMessages([]);

    try {
      const res = await fetch(`${API_BASE_URL}/api/chat/conversations/${conv.id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error('Failed to load messages');
      const data = await res.json();
      setActiveConversation(data);
      setMessages(data.messages ?? []);
    } catch {
      Alert.alert('Error', 'Could not load conversation.');
    } finally {
      setLoadingMessages(false);
    }
  }

  async function startNewConversation(deckId?: number | null) {
    const url = `${API_BASE_URL}/api/chat/conversations`;
    const payload = { deck_id: deckId ?? null };

    try {
      console.log('[assistant] create conversation request', {
        url,
        apiBaseUrl: API_BASE_URL,
        payload,
        hasToken: Boolean(token),
      });

      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });

      const responseText = await res.text();

      console.log('[assistant] create conversation response', {
        url,
        status: res.status,
        ok: res.ok,
        contentType: res.headers.get('content-type'),
        body: responseText,
      });

      if (!res.ok) {
        throw new Error(`Create conversation failed with status ${res.status}`);
      }

      const conv: Conversation = JSON.parse(responseText);
      setConversations((prev) => [conv, ...prev]);
      setActiveConversation(conv);
      setMessages([]);
    } catch (error) {
      console.error('[assistant] create conversation error', {
        url,
        apiBaseUrl: API_BASE_URL,
        payload,
        error,
      });
      Alert.alert('Error', 'Could not start a new conversation.');
    }
  }

  async function deleteConversation(conversationId: number) {
    setDeletingConversationId(conversationId);

    try {
      const res = await fetch(`${API_BASE_URL}/api/chat/conversations/${conversationId}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        throw new Error('Failed to delete conversation');
      }

      setConversations((prev) => prev.filter((conversation) => conversation.id !== conversationId));
      setActiveConversation((prev) => (prev?.id === conversationId ? null : prev));
      setMessages((prev) => (activeConversation?.id === conversationId ? [] : prev));
    } catch {
      Alert.alert('Error', 'Could not delete conversation.');
    } finally {
      setDeletingConversationId(null);
    }
  }

  function confirmDeleteConversation(conversation: Conversation) {
    if (deletingConversationId != null) return;

    Alert.alert('Delete Conversation', `Delete "${conversation.title ?? 'New conversation'}"?`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: () => deleteConversation(conversation.id),
      },
    ]);
  }

  useEffect(() => {
    const rawDeckId = params.deckId;
    if (!rawDeckId || activeConversation) return;
    if (handledDeckParamRef.current === rawDeckId) return;

    const parsedDeckId = Number(rawDeckId);
    if (!Number.isFinite(parsedDeckId)) return;

    handledDeckParamRef.current = rawDeckId;
    startNewConversation(parsedDeckId);
  }, [activeConversation, params.deckId]);

  useEffect(() => {
    if (!activeConversation || activeConversation.deck || loadingMessages || messages.length > 0) return;
    if (autoOpenedBuilderRef.current === activeConversation.id) return;

    autoOpenedBuilderRef.current = activeConversation.id;
    setBuilderModalVisible(true);
  }, [activeConversation, loadingMessages, messages.length]);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedCommanderQuery(commanderQuery.trim());
    }, 300);

    return () => clearTimeout(timer);
  }, [commanderQuery]);

  useEffect(() => {
    if (!COMMANDER_FORMATS.has(builderFormat)) {
      setCommanderResults([]);
      setCommanderLoading(false);
      return;
    }

    if (!debouncedCommanderQuery) {
      setCommanderResults([]);
      return;
    }

    if (
      selectedCommander &&
      debouncedCommanderQuery.toLowerCase() === selectedCommander.name.toLowerCase()
    ) {
      setCommanderResults([]);
      return;
    }

    let cancelled = false;

    async function searchCommanders() {
      setCommanderLoading(true);

      try {
        const response = await fetch(
          `${API_BASE_URL}/api/cards/search?q=${encodeURIComponent(debouncedCommanderQuery)}&format=${encodeURIComponent(builderFormat)}&commander_only=1`,
          {
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${token}`,
            },
          }
        );

        if (!response.ok) {
          throw new Error('Commander search failed');
        }

        const data: SearchableCard[] = await response.json();
        if (!cancelled) {
          setCommanderResults(data);
        }
      } catch {
        if (!cancelled) {
          setCommanderResults([]);
        }
      } finally {
        if (!cancelled) {
          setCommanderLoading(false);
        }
      }
    }

    void searchCommanders();

    return () => {
      cancelled = true;
    };
  }, [builderFormat, debouncedCommanderQuery, selectedCommander, token]);

  useEffect(() => {
    setSelectedCommander(null);
    setCommanderQuery('');
    setCommanderResults([]);

    if (COMMANDER_FORMATS.has(builderFormat)) {
      setBuilderColors([]);
      return;
    }

    setBuilderColors((current) => (current.length > 0 ? current : ['U']));
  }, [builderFormat]);

  // -------------------------------------------------------------------------
  // Send message
  // -------------------------------------------------------------------------

  async function sendMessage(overrideText?: string) {
    const text = (overrideText ?? inputText).trim();
    if (!text || !activeConversation) return;

    if (!overrideText) {
      setInputText('');
    }
    setSending(true);
    setIsStreaming(false);
    setThinkingLabel('VaultMage is thinking…');

    const STREAMING_ID = -1;

    const tempUserMsg: Message = {
      id: Date.now(),
      role: 'user',
      content: text,
      metadata: null,
      created_at: new Date().toISOString(),
    };

    const streamingMsg: Message = {
      id: STREAMING_ID,
      role: 'assistant',
      content: '',
      metadata: null,
      created_at: new Date().toISOString(),
    };

    setMessages((prev) => [...prev, tempUserMsg, streamingMsg]);

    await new Promise<void>((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${API_BASE_URL}/api/chat/conversations/${activeConversation!.id}/messages/stream`);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('Accept', 'text/event-stream');
      xhr.setRequestHeader('Authorization', `Bearer ${token}`);

      let processedLength = 0;
      let sseBuffer = '';
      let errorOccurred = false;

      const toolLabels: Record<string, string> = {
        get_collection:  'Checking your collection…',
        get_decks:       'Loading your decks…',
        search_cards:    'Searching cards…',
        search_scryfall: 'Looking up Scryfall…',
        propose_changes: 'Preparing upgrade suggestions…',
        propose_deck:    'Building deck proposal…',
      };

      function processLine(line: string) {
        if (!line.startsWith('data: ')) return;
        const raw = line.slice(6).trim();

        let event: { type: string; text?: string; content?: string; message_id?: number; deck_proposal?: DeckProposal | null; message?: string; round?: number; tools?: string[] };
        try {
          event = JSON.parse(raw);
        } catch {
          return;
        }

        if (event.type === 'thinking') {
          const firstTool = event.tools?.[0];
          setThinkingLabel(firstTool ? (toolLabels[firstTool] ?? 'Working…') : 'Working…');
        } else if (event.type === 'token') {
          setIsStreaming(true);
          setMessages((prev) =>
            prev.map((m) =>
              m.id === STREAMING_ID
                ? { ...m, content: (m.content ?? '') + (event.text ?? '') }
                : m
            )
          );
        } else if (event.type === 'done') {
          setMessages((prev) =>
            prev.map((m) =>
              m.id === STREAMING_ID
                ? {
                    ...m,
                    id: event.message_id!,
                    content: (m.content ?? '') || event.content || '',
                    metadata: event.deck_proposal ?? null,
                  }
                : m
            )
          );
          if (!activeConversation!.title) {
            fetchConversations();
            setActiveConversation((prev) =>
              prev ? { ...prev, title: text.slice(0, 80) } : prev
            );
          }
        } else if (event.type === 'error') {
          errorOccurred = true;
          setMessages((prev) => prev.filter((m) => m.id !== tempUserMsg.id && m.id !== STREAMING_ID));
          Alert.alert('Error', event.message ?? 'Streaming error');
        }
      }

      xhr.onprogress = () => {
        const newChunk = xhr.responseText.slice(processedLength);
        processedLength = xhr.responseText.length;

        sseBuffer += newChunk;
        const lines = sseBuffer.split('\n');
        sseBuffer = lines.pop() ?? '';
        for (const line of lines) processLine(line);
      };

      xhr.onload = () => {
        // Flush any remaining buffered data
        if (sseBuffer) processLine(sseBuffer);

        if (!errorOccurred && xhr.status >= 400) {
          setMessages((prev) => prev.filter((m) => m.id !== tempUserMsg.id && m.id !== STREAMING_ID));
          Alert.alert('Error', 'Failed to send message.');
        }

        setSending(false);
        setIsStreaming(false);
        resolve();
      };

      xhr.onerror = () => {
        setMessages((prev) => prev.filter((m) => m.id !== tempUserMsg.id && m.id !== STREAMING_ID));
        Alert.alert('Error', 'Network error. Please try again.');
        setSending(false);
        setIsStreaming(false);
        resolve();
      };

      xhr.send(JSON.stringify({ message: text }));
    });
  }

  function buildDeckPrompt() {
    const budget = builderBudget === 'custom' ? builderCustomBudget.trim() : builderBudget;
    if (!budget) return null;

    if (COMMANDER_FORMATS.has(builderFormat)) {
      if (!selectedCommander) return null;

      return [
        'Build me a brand-new deck.',
        `Format: ${titleCase(builderFormat)}.`,
        `Commander: ${selectedCommander.name}.`,
        `Search colors: [${(selectedCommander.color_identity?.length ? selectedCommander.color_identity : ['C']).join(', ')}].`,
        `Playstyle: ${builderPlaystyle}.`,
        `Budget: ${budget}.`,
        'These are the required deck-building details, so start building immediately and do not ask me to confirm them again.',
        'Prioritize cards I already own when possible.',
      ].join(' ');
    }

    if (builderColors.length === 0) return null;

    const colorNames = builderColors.includes('C')
      ? 'colorless cards only'
      : joinLabels(builderColors.map(formatColorLabel));

    return [
      'Build me a brand-new deck.',
      `Format: ${titleCase(builderFormat)}.`,
      `Colors: ${colorNames}.`,
      `Search colors: [${builderColors.join(', ')}].`,
      `Playstyle: ${builderPlaystyle}.`,
      `Budget: ${budget}.`,
      'These are the required deck-building details, so start building immediately and do not ask me to confirm them again.',
      'Prioritize cards I already own when possible.',
    ].join(' ');
  }

  async function submitBuilderBrief() {
    const prompt = buildDeckPrompt();
    if (!prompt) {
      Alert.alert('Budget needed', 'Enter a budget before starting the deck build.');
      return;
    }

    setBuilderModalVisible(false);
    await sendMessage(prompt);
  }

  function handleSendPress() {
    void sendMessage();
  }

  function toggleBuilderColor(color: BuilderColor) {
    setBuilderColors((current) => {
      if (color === 'C') {
        return current.includes('C') ? [] : ['C'];
      }

      const withoutColorless = current.filter((entry) => entry !== 'C');
      return withoutColorless.includes(color)
        ? withoutColorless.filter((entry) => entry !== color)
        : [...withoutColorless, color];
    });
  }

  function selectCommander(card: SearchableCard) {
    setSelectedCommander(card);
    setCommanderQuery('');
    setCommanderResults([]);
  }

  // -------------------------------------------------------------------------
  // Create deck from proposal
  // -------------------------------------------------------------------------

  async function handleCreateDeck(proposal: DeckProposal, messageId: number) {
    if (!activeConversation || creatingDeckRef.current) return;
    if (proposal.validation_message) {
      Alert.alert('Invalid proposal', proposal.validation_message);
      return;
    }
    creatingDeckRef.current = true;
    setCreatingDeck(true);

    try {
      const res = await fetch(
        `${API_BASE_URL}/api/chat/conversations/${activeConversation.id}/create-deck`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({
            message_id: messageId,
          }),
        }
      );

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to create deck');
      }

      const deck = await res.json();
      router.push(`/deck/${deck.id}`);
    } catch (error) {
      Alert.alert('Error', error instanceof Error ? error.message : 'Failed to create deck.');
    } finally {
      creatingDeckRef.current = false;
      setCreatingDeck(false);
    }
  }

  // -------------------------------------------------------------------------
  // Draft deck actions
  // -------------------------------------------------------------------------

  async function handleValidateDraft(draftId: number) {
    if (creatingDeckRef.current) return;
    creatingDeckRef.current = true;
    setCreatingDeck(true);

    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${draftId}/validate`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to save deck');
      }

      const deck = await res.json();
      router.push(`/deck/${deck.id}`);
    } catch (error) {
      Alert.alert('Error', error instanceof Error ? error.message : 'Failed to save deck.');
    } finally {
      creatingDeckRef.current = false;
      setCreatingDeck(false);
    }
  }

  async function handleDiscardDraft(draftId: number) {
    Alert.alert('Discard draft?', 'This deck will be permanently deleted.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Discard',
        style: 'destructive',
        onPress: async () => {
          try {
            await fetch(`${API_BASE_URL}/api/decks/${draftId}`, {
              method: 'DELETE',
              headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            });
          } catch {
            Alert.alert('Error', 'Could not discard the draft.');
          }
        },
      },
    ]);
  }

  // -------------------------------------------------------------------------
  // Scroll to bottom when messages change
  // -------------------------------------------------------------------------

  useEffect(() => {
    if (messages.length > 0) {
      setTimeout(() => flatListRef.current?.scrollToEnd({ animated: true }), 100);
    }
  }, [messages]);

  // -------------------------------------------------------------------------
  // Render: conversation list
  // -------------------------------------------------------------------------

  if (!activeConversation) {
    return (
      <View style={styles.container}>
        <View style={styles.headerRow}>
          <Text style={styles.header}>AI Assistant</Text>
          <TouchableOpacity style={styles.newChatBtn} onPress={() => startNewConversation()}>
            <Ionicons name="add" size={20} color="#fff" />
            <Text style={styles.newChatBtnText}>New Chat</Text>
          </TouchableOpacity>
        </View>

        {loadingList ? (
          <View style={styles.centered}>
            <ActivityIndicator color="#6C3CE1" size="large" />
          </View>
        ) : conversations.length === 0 ? (
          <View style={styles.emptyState}>
            <Ionicons name="chatbubbles-outline" size={64} color="#433647" />
            <Text style={styles.emptyTitle}>No conversations yet</Text>
            <Text style={styles.emptySubtitle}>
              {params.deckName
                ? `Start a chat about ${params.deckName}. VaultMage will use that deck and your collection.`
                : 'Tell VaultMage what kind of deck you want to build. It knows your collection.'}
            </Text>
            <TouchableOpacity style={styles.primaryCta} onPress={() => startNewConversation()}>
              <Text style={styles.primaryCtaText}>Start Building</Text>
            </TouchableOpacity>
          </View>
        ) : (
          <FlatList
            data={conversations}
            keyExtractor={(item) => item.id.toString()}
            contentContainerStyle={styles.listContent}
            renderItem={({ item }) => (
              <View style={styles.convItem}>
                <TouchableOpacity style={styles.convMain} onPress={() => openConversation(item)}>
                  <View style={styles.convIcon}>
                    <Ionicons name="chatbubble-ellipses" size={20} color="#6C3CE1" />
                  </View>
                  <View style={styles.convInfo}>
                    <Text style={styles.convTitle} numberOfLines={1}>
                      {item.title ?? 'New conversation'}
                    </Text>
                    <Text style={styles.convTime}>{timeAgo(item.updated_at)}</Text>
                  </View>
                </TouchableOpacity>
                <TouchableOpacity
                  style={styles.convDeleteBtn}
                  onPress={() => confirmDeleteConversation(item)}
                  disabled={deletingConversationId === item.id}
                >
                  {deletingConversationId === item.id ? (
                    <ActivityIndicator color="#ff9b9b" size="small" />
                  ) : (
                    <Ionicons name="trash-outline" size={18} color="#ff8c8c" />
                  )}
                </TouchableOpacity>
              </View>
            )}
          />
        )}
      </View>
    );
  }

  // -------------------------------------------------------------------------
  // Render: active chat
  // -------------------------------------------------------------------------

  const visibleMessages = messages.filter((m) => m.role !== 'tool');

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={90}
    >
      {/* Header */}
      <View style={styles.chatHeaderRow}>
        <TouchableOpacity style={styles.backBtn} onPress={() => setActiveConversation(null)}>
          <Ionicons name="chevron-back" size={22} color="#fff" />
        </TouchableOpacity>
        <Text style={styles.chatHeaderTitle} numberOfLines={1}>
          {activeConversation.title ?? 'New conversation'}
        </Text>
        <TouchableOpacity
          style={styles.chatHeaderIconBtn}
          onPress={() => confirmDeleteConversation(activeConversation)}
          disabled={deletingConversationId === activeConversation.id}
        >
          {deletingConversationId === activeConversation.id ? (
            <ActivityIndicator color="#ff9b9b" size="small" />
          ) : (
            <Ionicons name="trash-outline" size={20} color="#ff8c8c" />
          )}
        </TouchableOpacity>
        <TouchableOpacity style={styles.newChatIconBtn} onPress={() => startNewConversation()}>
          <Ionicons name="add-circle-outline" size={24} color="#6C3CE1" />
        </TouchableOpacity>
      </View>

      {activeConversation.deck ? (
        <TouchableOpacity
          style={styles.activeDeckBanner}
          onPress={() => router.push(`/deck/${activeConversation.deck!.id}`)}
          activeOpacity={0.8}
        >
          <Ionicons name="albums" size={16} color="#ffb36b" />
          <Text style={styles.activeDeckBannerText} numberOfLines={1}>
            Deck context: {activeConversation.deck.name}
            {activeConversation.deck.format ? ` • ${activeConversation.deck.format}` : ''}
          </Text>
        </TouchableOpacity>
      ) : null}

      {/* Messages */}
      {loadingMessages ? (
        <View style={styles.centered}>
          <ActivityIndicator color="#6C3CE1" size="large" />
        </View>
      ) : (
        <FlatList
          ref={flatListRef}
          data={visibleMessages}
          keyExtractor={(item) => item.id.toString()}
          contentContainerStyle={styles.messagesContent}
          ListEmptyComponent={
            <View style={styles.emptyChat}>
              <Ionicons name="sparkles" size={32} color="#6C3CE1" style={{ marginBottom: 12 }} />
              <Text style={styles.emptyChatText}>
                {activeConversation.deck
                  ? `Ask how to improve ${activeConversation.deck.name}, what to swap from your collection, or what to buy next.`
                  : "Tell me what kind of deck you want to build. I'll check your collection and craft something great."}
              </Text>
              {!activeConversation.deck ? (
                <TouchableOpacity style={styles.builderLaunchBtn} onPress={() => setBuilderModalVisible(true)}>
                  <Ionicons name="options-outline" size={16} color="#fff" />
                  <Text style={styles.builderLaunchBtnText}>Use deck builder brief</Text>
                </TouchableOpacity>
              ) : null}
            </View>
          }
          renderItem={({ item }) => (
            <MessageBubble
              message={item}
              onCreateDeck={handleCreateDeck}
              onValidateDraft={handleValidateDraft}
              onDiscardDraft={handleDiscardDraft}
              creating={creatingDeck}
              onCardPress={setSelectedProposalCard}
            />
          )}
        />
      )}

      {/* Thinking indicator — shown while waiting for first token */}
      {sending && !isStreaming ? (
        <View style={styles.thinkingRow}>
          <ActivityIndicator size="small" color="#6C3CE1" style={{ marginRight: 8 }} />
          <Text style={styles.thinkingText}>{thinkingLabel}</Text>
        </View>
      ) : null}

      {/* Card detail modal */}
      <Modal
        visible={!!selectedProposalCard}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setSelectedProposalCard(null)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            {selectedProposalCard && (
              <>
                <View style={styles.modalHeader}>
                  <Text style={styles.modalTitle} numberOfLines={1}>
                    {selectedProposalCard.name ?? `Card #${selectedProposalCard.card_id}`}
                  </Text>
                  <TouchableOpacity style={styles.modalCloseBtn} onPress={() => setSelectedProposalCard(null)}>
                    <Ionicons name="close" size={24} color="#fff" />
                  </TouchableOpacity>
                </View>

                <ScrollView contentContainerStyle={styles.modalScroll}>
                  {selectedProposalCard.image_uri ? (
                    <Image
                      source={{ uri: selectedProposalCard.image_uri }}
                      style={styles.modalCardImage}
                      resizeMode="contain"
                    />
                  ) : (
                    <View style={[styles.modalCardImage, styles.modalCardImagePlaceholder]}>
                      <Ionicons name="image-outline" size={48} color="#444" />
                    </View>
                  )}

                  <View style={styles.modalCardInfo}>
                    {selectedProposalCard.type_line ? (
                      <Text style={styles.modalCardType}>{selectedProposalCard.type_line}</Text>
                    ) : null}
                    {selectedProposalCard.mana_cost ? (
                      <Text style={styles.modalCardMana}>{selectedProposalCard.mana_cost}</Text>
                    ) : null}

                    <View style={styles.modalCardBadgeRow}>
                      <View style={styles.modalCardQtyBadge}>
                        <Text style={styles.modalCardQtyText}>×{selectedProposalCard.quantity} in deck</Text>
                      </View>
                      <View style={[
                        styles.modalCardOwnedBadge,
                        selectedProposalCard.owned_quantity > 0 ? styles.ownedBadgeYes : styles.ownedBadgeNo,
                      ]}>
                        <Text style={styles.modalCardOwnedText}>
                          {selectedProposalCard.owned_quantity > 0
                            ? `You own ${selectedProposalCard.owned_quantity}`
                            : 'Not in collection'}
                        </Text>
                      </View>
                    </View>

                    {selectedProposalCard.role ? (
                      <View style={styles.modalCardSection}>
                        <Text style={styles.modalCardSectionLabel}>Role</Text>
                        <Text style={styles.modalCardSectionText}>{selectedProposalCard.role}</Text>
                      </View>
                    ) : null}

                    {selectedProposalCard.reason ? (
                      <View style={styles.modalCardSection}>
                        <Text style={styles.modalCardSectionLabel}>Why this card</Text>
                        <Text style={styles.modalCardSectionText}>{selectedProposalCard.reason}</Text>
                      </View>
                    ) : null}
                  </View>
                </ScrollView>
              </>
            )}
          </View>
        </View>
      </Modal>

      <Modal
        visible={builderModalVisible}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setBuilderModalVisible(false)}
      >
        <KeyboardAvoidingView
          style={styles.modalOverlay}
          behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
          keyboardVerticalOffset={24}
        >
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Deck Builder Brief</Text>
              <TouchableOpacity style={styles.modalCloseBtn} onPress={() => setBuilderModalVisible(false)}>
                <Ionicons name="close" size={24} color="#fff" />
              </TouchableOpacity>
            </View>

            <ScrollView
              contentContainerStyle={styles.builderModalScroll}
              keyboardShouldPersistTaps="handled"
              showsVerticalScrollIndicator={false}
            >
              <Text style={styles.builderIntroText}>
                Pick the three things VaultMage always needs before it starts building.
              </Text>

              <View style={styles.builderSection}>
                <Text style={styles.builderSectionLabel}>Format</Text>
                <View style={styles.builderChipWrap}>
                  {BUILD_FORMAT_OPTIONS.map((option) => (
                    <TouchableOpacity
                      key={option.value}
                      style={[styles.builderChip, builderFormat === option.value && styles.builderChipActive]}
                      onPress={() => setBuilderFormat(option.value)}
                    >
                      <Text
                        style={[styles.builderChipText, builderFormat === option.value && styles.builderChipTextActive]}
                      >
                        {option.label}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </View>
              </View>

              <View style={styles.builderSection}>
                <Text style={styles.builderSectionLabel}>Playstyle</Text>
                <View style={styles.builderChipWrap}>
                  {BUILD_PLAYSTYLE_OPTIONS.map((option) => (
                    <TouchableOpacity
                      key={option.value}
                      style={[styles.builderChip, builderPlaystyle === option.value && styles.builderChipActive]}
                      onPress={() => setBuilderPlaystyle(option.value)}
                    >
                      <Text
                        style={[
                          styles.builderChipText,
                          builderPlaystyle === option.value && styles.builderChipTextActive,
                        ]}
                      >
                        {option.label}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </View>
              </View>

              {COMMANDER_FORMATS.has(builderFormat) ? (
                <View style={styles.builderSection}>
                  <Text style={styles.builderSectionLabel}>Commander</Text>
                  <TextInput
                    style={styles.builderBudgetInput}
                    placeholder={builderFormat === 'brawl' ? 'Search for a commander' : 'Search for a commander'}
                    placeholderTextColor="#666"
                    value={commanderQuery}
                    onChangeText={setCommanderQuery}
                    autoCorrect={false}
                    autoCapitalize="words"
                  />
                  {selectedCommander ? (
                    <View style={styles.builderSelectedCard}>
                      <View style={styles.builderSelectedCardText}>
                        <Text style={styles.builderSelectedCardName}>{selectedCommander.name}</Text>
                        <Text style={styles.builderSelectedCardMeta}>
                          {selectedCommander.type_line}
                          {selectedCommander.color_identity?.length
                            ? ` • ${selectedCommander.color_identity.join('/')}`
                            : ''}
                        </Text>
                      </View>
                      <TouchableOpacity
                        onPress={() => {
                          setSelectedCommander(null);
                          setCommanderResults([]);
                          setCommanderQuery('');
                        }}
                      >
                        <Ionicons name="close-circle" size={20} color="#9d97b2" />
                      </TouchableOpacity>
                    </View>
                  ) : null}
                  {commanderLoading ? (
                    <View style={styles.builderSearchState}>
                      <ActivityIndicator size="small" color="#6C3CE1" />
                      <Text style={styles.builderSearchStateText}>Searching local cards and Scryfall…</Text>
                    </View>
                  ) : debouncedCommanderQuery ? (
                    <View style={styles.builderSearchResults}>
                      {commanderResults.length > 0 ? (
                        commanderResults.slice(0, 6).map((card) => (
                          <TouchableOpacity
                            key={card.scryfall_id}
                            style={styles.builderSearchResultRow}
                            onPress={() => selectCommander(card)}
                          >
                            <View style={styles.builderSearchResultText}>
                              <Text style={styles.builderSearchResultName}>{card.name}</Text>
                              <Text style={styles.builderSearchResultMeta}>
                                {card.type_line}
                                {card.color_identity?.length ? ` • ${card.color_identity.join('/')}` : ''}
                              </Text>
                            </View>
                            <Ionicons
                              name={selectedCommander?.scryfall_id === card.scryfall_id ? 'checkmark-circle' : 'chevron-forward'}
                              size={18}
                              color={selectedCommander?.scryfall_id === card.scryfall_id ? '#4ade80' : '#888'}
                            />
                          </TouchableOpacity>
                        ))
                      ) : (
                        <Text style={styles.builderSearchEmpty}>No commanders matched that search.</Text>
                      )}
                    </View>
                  ) : (
                    <Text style={styles.builderHintText}>Search by commander name. Results pull from local cards first, then Scryfall.</Text>
                  )}
                </View>
              ) : (
                <View style={styles.builderSection}>
                  <Text style={styles.builderSectionLabel}>Colors</Text>
                  <View style={styles.builderChipWrap}>
                    {BUILDER_COLOR_OPTIONS.map((color) => (
                      <TouchableOpacity
                        key={color}
                        style={[styles.builderChip, builderColors.includes(color) && styles.builderChipActive]}
                        onPress={() => toggleBuilderColor(color)}
                      >
                        <Text
                          style={[styles.builderChipText, builderColors.includes(color) && styles.builderChipTextActive]}
                        >
                          {color}
                        </Text>
                      </TouchableOpacity>
                    ))}
                  </View>
                  <Text style={styles.builderHintText}>
                    {builderColors.length === 0
                      ? 'Pick at least one color.'
                      : builderColors.includes('C')
                        ? 'Colorless only.'
                        : `Selected: ${joinLabels(builderColors.map(formatColorLabel))}.`}
                  </Text>
                </View>
              )}

              <View style={styles.builderSection}>
                <Text style={styles.builderSectionLabel}>Budget</Text>
                <View style={styles.builderChipWrap}>
                  {BUILD_BUDGET_OPTIONS.map((option) => (
                    <TouchableOpacity
                      key={option.value}
                      style={[styles.builderChip, builderBudget === option.value && styles.builderChipActive]}
                      onPress={() => setBuilderBudget(option.value)}
                    >
                      <Text
                        style={[styles.builderChipText, builderBudget === option.value && styles.builderChipTextActive]}
                      >
                        {option.label}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </View>
                {builderBudget === 'custom' ? (
                  <TextInput
                    style={styles.builderBudgetInput}
                    placeholder='Example: under $75 or around $150'
                    placeholderTextColor="#666"
                    value={builderCustomBudget}
                    onChangeText={setBuilderCustomBudget}
                    maxLength={80}
                  />
                ) : null}
              </View>

              <View style={styles.builderPreview}>
                <Text style={styles.builderPreviewLabel}>Preview</Text>
                <Text style={styles.builderPreviewText}>
                  {buildDeckPrompt() ?? 'Choose a budget to generate the request.'}
                </Text>
              </View>

              <TouchableOpacity
                style={[styles.createDeckBtn, !buildDeckPrompt() || sending ? styles.buttonDisabled : null]}
                onPress={submitBuilderBrief}
                disabled={!buildDeckPrompt() || sending}
              >
                <Ionicons name="sparkles" size={16} color="#fff" style={{ marginRight: 6 }} />
                <Text style={styles.createDeckBtnText}>Start Build</Text>
              </TouchableOpacity>
            </ScrollView>
          </View>
        </KeyboardAvoidingView>
      </Modal>

      {/* Input bar */}
      <View style={styles.inputBar}>
        {!activeConversation.deck ? (
          <TouchableOpacity style={styles.inputUtilityBtn} onPress={() => setBuilderModalVisible(true)}>
            <Ionicons name="options-outline" size={18} color="#fff" />
          </TouchableOpacity>
        ) : null}
        <TextInput
          style={styles.input}
          placeholder="Ask anything about your deck…"
          placeholderTextColor="#555"
          value={inputText}
          onChangeText={setInputText}
          multiline
          maxLength={2000}
          onSubmitEditing={handleSendPress}
          blurOnSubmit={false}
        />
        <TouchableOpacity
          style={[styles.sendBtn, (!inputText.trim() || sending) && styles.sendBtnDisabled]}
          onPress={handleSendPress}
          disabled={!inputText.trim() || sending}
        >
          <Ionicons name="arrow-up" size={20} color="#fff" />
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f0f1a' },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },

  // Conversation list header
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: 56,
    paddingBottom: 12,
  },
  header: { color: '#fff', fontSize: 22, fontWeight: '700' },
  newChatBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#6C3CE1',
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 20,
    gap: 4,
  },
  newChatBtnText: { color: '#fff', fontWeight: '600', fontSize: 14 },

  // List
  listContent: { paddingHorizontal: 16, paddingBottom: 24 },
  convItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  convMain: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
  },
  convIcon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 14,
  },
  convInfo: { flex: 1 },
  convTitle: { color: '#fff', fontSize: 15, fontWeight: '600', marginBottom: 3 },
  convTime: { color: '#666', fontSize: 12 },
  convDeleteBtn: {
    width: 36,
    height: 36,
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: 10,
  },

  // Empty states
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
    marginTop: 48,
  },
  emptyTitle: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 16, marginBottom: 8 },
  emptySubtitle: { color: '#888', fontSize: 14, textAlign: 'center', lineHeight: 20, marginBottom: 24 },
  primaryCta: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 24,
    borderRadius: 8,
  },
  primaryCtaText: { color: '#fff', fontWeight: '600', fontSize: 15 },

  // Chat header
  chatHeaderRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingTop: 56,
    paddingBottom: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#1a1a2e',
  },
  backBtn: { padding: 6, marginRight: 4 },
  chatHeaderTitle: { flex: 1, color: '#fff', fontSize: 16, fontWeight: '600' },
  chatHeaderIconBtn: { padding: 6 },
  newChatIconBtn: { padding: 6 },
  activeDeckBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginHorizontal: 16,
    marginTop: 12,
    marginBottom: 4,
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderRadius: 12,
    backgroundColor: '#171722',
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  activeDeckBannerText: {
    flex: 1,
    color: '#f4e8dc',
    fontSize: 13,
    fontWeight: '600',
  },

  // Messages
  messagesContent: { paddingHorizontal: 16, paddingVertical: 16, paddingBottom: 8 },
  emptyChat: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 32,
    marginTop: 80,
  },
  emptyChatText: { color: '#666', fontSize: 14, textAlign: 'center', lineHeight: 22 },
  builderLaunchBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 18,
    backgroundColor: '#6C3CE1',
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: 999,
  },
  builderLaunchBtnText: { color: '#fff', fontSize: 13, fontWeight: '700' },

  // Bubbles
  bubbleWrapper: { flexDirection: 'row', marginBottom: 14, alignItems: 'flex-end' },
  bubbleWrapperUser: { justifyContent: 'flex-end' },
  bubbleWrapperAssistant: { justifyContent: 'flex-start' },
  avatarDot: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: '#1a1a2e',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 8,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  bubble: { maxWidth: '82%', borderRadius: 16, padding: 12 },
  bubbleUser: { backgroundColor: '#6C3CE1', borderBottomRightRadius: 4 },
  bubbleAssistant: { backgroundColor: '#1a1a2e', borderBottomLeftRadius: 4, borderWidth: 1, borderColor: '#2a2a3e' },
  bubbleText: { fontSize: 15, lineHeight: 22 },
  bubbleTextUser: { color: '#fff' },
  bubbleTextAssistant: { color: '#e8e0f0' },

  // Card thumbnail in proposal row
  proposalCardThumb: {
    width: 36,
    height: 50,
    borderRadius: 4,
    marginRight: 10,
    overflow: 'hidden',
  },
  proposalCardThumbPlaceholder: {
    backgroundColor: '#1e1e30',
    alignItems: 'center',
    justifyContent: 'center',
  },

  // Card detail modal
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.7)',
    justifyContent: 'flex-end',
  },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    maxHeight: '85%',
    paddingBottom: 32,
  },
  modalHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingVertical: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  modalTitle: {
    flex: 1,
    color: '#fff',
    fontSize: 17,
    fontWeight: '700',
  },
  modalCloseBtn: {
    padding: 4,
    marginLeft: 8,
  },
  modalScroll: {
    paddingBottom: 16,
  },
  builderModalScroll: {
    paddingHorizontal: 20,
    paddingBottom: 24,
    gap: 18,
  },
  builderIntroText: {
    color: '#aaa',
    fontSize: 14,
    lineHeight: 20,
    marginTop: 16,
  },
  builderSection: {
    gap: 10,
  },
  builderSectionLabel: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '700',
  },
  builderChipWrap: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
  },
  builderChip: {
    paddingHorizontal: 12,
    paddingVertical: 9,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: '#3a2a5e',
    backgroundColor: '#0f0f1a',
  },
  builderChipActive: {
    backgroundColor: '#6C3CE1',
    borderColor: '#6C3CE1',
  },
  builderChipText: {
    color: '#d9cfe8',
    fontSize: 13,
    fontWeight: '600',
  },
  builderChipTextActive: {
    color: '#fff',
  },
  builderBudgetInput: {
    backgroundColor: '#0f0f1a',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    color: '#fff',
    fontSize: 14,
  },
  builderHintText: {
    color: '#8e8aa3',
    fontSize: 13,
    lineHeight: 18,
  },
  builderSelectedCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: '#0f0f1a',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  builderSelectedCardText: {
    flex: 1,
  },
  builderSelectedCardName: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '700',
  },
  builderSelectedCardMeta: {
    color: '#9d97b2',
    fontSize: 12,
    marginTop: 2,
  },
  builderSearchState: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  builderSearchStateText: {
    color: '#9d97b2',
    fontSize: 13,
  },
  builderSearchResults: {
    backgroundColor: '#0f0f1a',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 12,
    overflow: 'hidden',
  },
  builderSearchResultRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 12,
    paddingVertical: 11,
    borderBottomWidth: 1,
    borderBottomColor: '#1a1a2e',
  },
  builderSearchResultText: {
    flex: 1,
  },
  builderSearchResultName: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '600',
  },
  builderSearchResultMeta: {
    color: '#8e8aa3',
    fontSize: 12,
    marginTop: 2,
  },
  builderSearchEmpty: {
    color: '#8e8aa3',
    fontSize: 13,
    paddingHorizontal: 12,
    paddingVertical: 14,
  },
  builderPreview: {
    backgroundColor: '#0f0f1a',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 14,
    padding: 14,
    marginTop: 4,
  },
  builderPreviewLabel: {
    color: '#6C3CE1',
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
    marginBottom: 6,
  },
  builderPreviewText: {
    color: '#e8e0f0',
    fontSize: 14,
    lineHeight: 20,
  },
  modalCardImage: {
    width: '55%',
    aspectRatio: 0.71,
    borderRadius: 12,
    alignSelf: 'center',
    marginVertical: 16,
  },
  modalCardImagePlaceholder: {
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalCardInfo: {
    paddingHorizontal: 20,
    gap: 8,
  },
  modalCardType: {
    color: '#aaa',
    fontSize: 14,
  },
  modalCardMana: {
    color: '#c8a0ff',
    fontSize: 14,
    fontWeight: '600',
  },
  modalCardBadgeRow: {
    flexDirection: 'row',
    gap: 8,
    marginTop: 4,
    flexWrap: 'wrap',
  },
  modalCardQtyBadge: {
    backgroundColor: '#2a2a3e',
    borderRadius: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  modalCardQtyText: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
  },
  modalCardOwnedBadge: {
    borderRadius: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  ownedBadgeYes: { backgroundColor: '#14532d' },
  ownedBadgeNo: { backgroundColor: '#450a0a' },
  modalCardOwnedText: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
  },
  modalCardSection: {
    marginTop: 8,
  },
  modalCardSectionLabel: {
    color: '#6C3CE1',
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.6,
    textTransform: 'uppercase',
    marginBottom: 4,
  },
  modalCardSectionText: {
    color: '#ccc',
    fontSize: 14,
    lineHeight: 20,
  },

  // Proposal card
  proposalCard: {
    backgroundColor: '#0f0f1a',
    borderRadius: 12,
    padding: 14,
    borderWidth: 1,
    borderColor: '#3a2a5e',
    marginTop: 6,
  },
  proposalHeader: { flexDirection: 'row', alignItems: 'center', marginBottom: 2 },
  proposalTitle: { color: '#fff', fontSize: 16, fontWeight: '700', flex: 1 },
  proposalMeta: { color: '#6C3CE1', fontSize: 11, fontWeight: '700', marginBottom: 6, letterSpacing: 0.6 },
  proposalStrategy: { color: '#888', fontSize: 13, lineHeight: 18, marginBottom: 8 },
  proposalWarning: { color: '#fbbf24', fontSize: 12, lineHeight: 17, marginBottom: 8 },
  proposalDivider: { height: 1, backgroundColor: '#2a2a3e', marginBottom: 10 },
  changeSection: { marginBottom: 10 },
  changeSectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginBottom: 8,
  },
  changeSectionTitle: {
    color: '#f1e6dc',
    fontSize: 13,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  proposalCardRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#1e1e30',
  },
  proposalCardLeft: { flex: 1 },
  proposalCardName: { color: '#fff', fontSize: 14, fontWeight: '600', marginBottom: 2 },
  proposalCardMeta: { color: '#666', fontSize: 12, marginBottom: 2 },
  proposalCardReason: { color: '#888', fontSize: 12, lineHeight: 16, fontStyle: 'italic' },
  proposalCardRight: { alignItems: 'flex-end', marginLeft: 10 },
  proposalCardQty: { color: '#fff', fontSize: 15, fontWeight: '700' },
  deltaAddedText: { color: '#4ade80' },
  deltaRemovedText: { color: '#f87171' },
  proposalCardOwned: { fontSize: 11, marginTop: 2 },
  ownedYes: { color: '#4ade80' },
  ownedNo: { color: '#f87171' },
  showMoreBtn: { paddingVertical: 10, alignItems: 'center' },
  showMoreText: { color: '#6C3CE1', fontSize: 13, fontWeight: '600' },
  createDeckBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#6C3CE1',
    borderRadius: 10,
    paddingVertical: 12,
    marginTop: 12,
  },
  createDeckBtnText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  buttonDisabled: { opacity: 0.55 },
  draftActionRow: { flexDirection: 'row', gap: 8, marginTop: 12 },
  validateDeckBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#166534',
    borderRadius: 10,
    paddingVertical: 12,
  },
  discardDeckBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 16,
    borderWidth: 1,
    borderColor: '#f87171',
  },
  discardDeckBtnText: { color: '#f87171', fontWeight: '600', fontSize: 14 },

  // Thinking
  thinkingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingVertical: 8,
  },
  thinkingText: { color: '#666', fontSize: 13 },

  // Input bar
  inputBar: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderTopWidth: 1,
    borderTopColor: '#1a1a2e',
    backgroundColor: '#0f0f1a',
    gap: 8,
  },
  inputUtilityBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  input: {
    flex: 1,
    backgroundColor: '#1a1a2e',
    borderRadius: 20,
    paddingHorizontal: 16,
    paddingVertical: 10,
    color: '#fff',
    fontSize: 15,
    borderWidth: 1,
    borderColor: '#2a2a3e',
    maxHeight: 120,
  },
  sendBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#6C3CE1',
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendBtnDisabled: { opacity: 0.4 },
});
