import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Conversation = {
  id: number;
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
  deck_name: string;
  format: string | null;
  strategy_summary: string | null;
  cards: ProposalCard[];
};

type Message = {
  id: number;
  role: 'user' | 'assistant' | 'tool';
  content: string | null;
  metadata: DeckProposal | null;
  created_at: string;
};

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

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function ProposalBubble({
  proposal,
  onCreateDeck,
  creating,
}: {
  proposal: DeckProposal;
  onCreateDeck: (proposal: DeckProposal) => void;
  creating: boolean;
}) {
  const [expanded, setExpanded] = useState(false);
  const visible = expanded ? proposal.cards : proposal.cards.slice(0, 6);

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

      <View style={styles.proposalDivider} />

      {visible.map((card) => (
        <View key={card.card_id} style={styles.proposalCardRow}>
          <View style={styles.proposalCardLeft}>
            <Text style={styles.proposalCardName}>{card.name ?? `Card #${card.card_id}`}</Text>
            <Text style={styles.proposalCardMeta}>
              {card.type_line ?? ''}
              {card.mana_cost ? `  ${card.mana_cost}` : ''}
            </Text>
            {card.reason ? <Text style={styles.proposalCardReason}>{card.reason}</Text> : null}
          </View>
          <View style={styles.proposalCardRight}>
            <Text style={styles.proposalCardQty}>×{card.quantity}</Text>
            <Text style={[styles.proposalCardOwned, card.owned_quantity > 0 ? styles.ownedYes : styles.ownedNo]}>
              {card.owned_quantity > 0 ? `own ${card.owned_quantity}` : 'not owned'}
            </Text>
          </View>
        </View>
      ))}

      {proposal.cards.length > 6 ? (
        <TouchableOpacity onPress={() => setExpanded((v) => !v)} style={styles.showMoreBtn}>
          <Text style={styles.showMoreText}>
            {expanded ? 'Show less' : `Show all ${proposal.cards.length} cards`}
          </Text>
        </TouchableOpacity>
      ) : null}

      <TouchableOpacity
        style={[styles.createDeckBtn, creating && styles.buttonDisabled]}
        onPress={() => onCreateDeck(proposal)}
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
    </View>
  );
}

function MessageBubble({
  message,
  onCreateDeck,
  creating,
}: {
  message: Message;
  onCreateDeck: (proposal: DeckProposal) => void;
  creating: boolean;
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
            onCreateDeck={onCreateDeck}
            creating={creating}
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

  // Conversation list view
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [loadingList, setLoadingList] = useState(true);

  // Active chat view
  const [activeConversation, setActiveConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loadingMessages, setLoadingMessages] = useState(false);

  // Sending
  const [inputText, setInputText] = useState('');
  const [sending, setSending] = useState(false);

  // Deck creation
  const [creatingDeck, setCreatingDeck] = useState(false);

  const flatListRef = useRef<FlatList>(null);

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
      setMessages(data.messages ?? []);
    } catch {
      Alert.alert('Error', 'Could not load conversation.');
    } finally {
      setLoadingMessages(false);
    }
  }

  async function startNewConversation() {
    try {
      const res = await fetch(`${API_BASE_URL}/api/chat/conversations`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error();
      const conv: Conversation = await res.json();
      setConversations((prev) => [conv, ...prev]);
      setActiveConversation(conv);
      setMessages([]);
    } catch {
      Alert.alert('Error', 'Could not start a new conversation.');
    }
  }

  // -------------------------------------------------------------------------
  // Send message
  // -------------------------------------------------------------------------

  async function sendMessage() {
    const text = inputText.trim();
    if (!text || !activeConversation) return;

    setInputText('');
    setSending(true);

    // Optimistic user bubble
    const tempUserMsg: Message = {
      id: Date.now(),
      role: 'user',
      content: text,
      metadata: null,
      created_at: new Date().toISOString(),
    };
    setMessages((prev) => [...prev, tempUserMsg]);

    try {
      const res = await fetch(
        `${API_BASE_URL}/api/chat/conversations/${activeConversation.id}/messages`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ message: text }),
        }
      );

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to send message');
      }

      const data = await res.json();
      const assistantMsg: Message = data.message;

      // If deck_proposal came back, attach it to the message metadata
      if (data.deck_proposal && !assistantMsg.metadata) {
        assistantMsg.metadata = data.deck_proposal;
      }

      setMessages((prev) => [...prev, assistantMsg]);

      // Update conversation title if it was auto-set
      if (!activeConversation.title) {
        fetchConversations();
        setActiveConversation((prev) =>
          prev ? { ...prev, title: text.slice(0, 80) } : prev
        );
      }
    } catch (error) {
      // Remove optimistic message on failure
      setMessages((prev) => prev.filter((m) => m.id !== tempUserMsg.id));
      Alert.alert('Error', error instanceof Error ? error.message : 'Failed to send message.');
    } finally {
      setSending(false);
    }
  }

  // -------------------------------------------------------------------------
  // Create deck from proposal
  // -------------------------------------------------------------------------

  async function handleCreateDeck(proposal: DeckProposal) {
    if (!activeConversation) return;
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
            deck_name: proposal.deck_name,
            format: proposal.format,
            strategy_summary: proposal.strategy_summary,
            cards: proposal.cards.map((c) => ({ card_id: c.card_id, quantity: c.quantity })),
          }),
        }
      );

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to create deck');
      }

      Alert.alert('Deck created!', `"${proposal.deck_name}" has been added to My Decks.`);
    } catch (error) {
      Alert.alert('Error', error instanceof Error ? error.message : 'Failed to create deck.');
    } finally {
      setCreatingDeck(false);
    }
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
          <TouchableOpacity style={styles.newChatBtn} onPress={startNewConversation}>
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
              Tell VaultMage what kind of deck you want to build. It knows your collection.
            </Text>
            <TouchableOpacity style={styles.primaryCta} onPress={startNewConversation}>
              <Text style={styles.primaryCtaText}>Start Building</Text>
            </TouchableOpacity>
          </View>
        ) : (
          <FlatList
            data={conversations}
            keyExtractor={(item) => item.id.toString()}
            contentContainerStyle={styles.listContent}
            renderItem={({ item }) => (
              <TouchableOpacity style={styles.convItem} onPress={() => openConversation(item)}>
                <View style={styles.convIcon}>
                  <Ionicons name="chatbubble-ellipses" size={20} color="#6C3CE1" />
                </View>
                <View style={styles.convInfo}>
                  <Text style={styles.convTitle} numberOfLines={1}>
                    {item.title ?? 'New conversation'}
                  </Text>
                  <Text style={styles.convTime}>{timeAgo(item.updated_at)}</Text>
                </View>
                <Ionicons name="chevron-forward" size={20} color="#8a7d8f" />
              </TouchableOpacity>
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
        <TouchableOpacity style={styles.newChatIconBtn} onPress={startNewConversation}>
          <Ionicons name="add-circle-outline" size={24} color="#6C3CE1" />
        </TouchableOpacity>
      </View>

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
                Tell me what kind of deck you want to build. I'll check your collection and craft something great.
              </Text>
            </View>
          }
          renderItem={({ item }) => (
            <MessageBubble
              message={item}
              onCreateDeck={handleCreateDeck}
              creating={creatingDeck}
            />
          )}
        />
      )}

      {/* Thinking indicator */}
      {sending ? (
        <View style={styles.thinkingRow}>
          <ActivityIndicator size="small" color="#6C3CE1" style={{ marginRight: 8 }} />
          <Text style={styles.thinkingText}>VaultMage is thinking…</Text>
        </View>
      ) : null}

      {/* Input bar */}
      <View style={styles.inputBar}>
        <TextInput
          style={styles.input}
          placeholder="Ask anything about your deck…"
          placeholderTextColor="#555"
          value={inputText}
          onChangeText={setInputText}
          multiline
          maxLength={2000}
          onSubmitEditing={sendMessage}
          blurOnSubmit={false}
        />
        <TouchableOpacity
          style={[styles.sendBtn, (!inputText.trim() || sending) && styles.sendBtnDisabled]}
          onPress={sendMessage}
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
  newChatIconBtn: { padding: 6 },

  // Messages
  messagesContent: { paddingHorizontal: 16, paddingVertical: 16, paddingBottom: 8 },
  emptyChat: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 32,
    marginTop: 80,
  },
  emptyChatText: { color: '#666', fontSize: 14, textAlign: 'center', lineHeight: 22 },

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
  proposalDivider: { height: 1, backgroundColor: '#2a2a3e', marginBottom: 10 },
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
