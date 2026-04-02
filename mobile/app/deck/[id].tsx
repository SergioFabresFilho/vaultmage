import { API_BASE_URL } from '@/lib/api';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Dimensions,
  Image,
  Modal,
  Platform,
  ScrollView,
  SectionList,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { SvgUri } from 'react-native-svg';
import { useAuth } from '@/context/AuthContext';

const { width: SCREEN_WIDTH } = Dimensions.get('window');

const FORMATS: Record<string, string> = {
  standard: 'Standard',
  pioneer: 'Pioneer',
  modern: 'Modern',
  legacy: 'Legacy',
  vintage: 'Vintage',
  edh: 'EDH / Commander',
  commander: 'Commander',
  brawl: 'Brawl',
  pauper: 'Pauper',
  casual: 'Casual',
};

const TYPE_ORDER = ['Planeswalker', 'Creature', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land', 'Other'];

const COLOR_DEFS: Record<string, { bg: string; text: string }> = {
  W: { bg: '#f9faf4', text: '#333' },
  U: { bg: '#0e68ab', text: '#fff' },
  B: { bg: '#2a2a2a', text: '#fff' },
  R: { bg: '#d3202a', text: '#fff' },
  G: { bg: '#00733e', text: '#fff' },
};

type Card = {
  id: number;
  name: string;
  type_line: string;
  mana_cost: string | null;
  image_uri: string | null;
  set_name: string;
  rarity: string;
  price_usd: number | null;
  pivot: {
    quantity: number;
    is_sideboard: boolean;
    is_commander: boolean;
  };
};

type Deck = {
  id: number;
  name: string;
  format: string | null;
  description: string | null;
  color_identity: string[] | null;
  cards: Card[];
};

type Section = {
  title: string;
  data: Card[];
};

function parseManaSymbols(manaCost: string): string[] {
  const matches = manaCost.match(/\{[^}]+\}/g);
  return matches ?? [];
}

function ManaCost({ cost, size = 14 }: { cost: string; size?: number }) {
  const symbols = parseManaSymbols(cost);
  if (symbols.length === 0) return null;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 1 }}>
      {symbols.map((sym, i) => {
        const code = sym.slice(1, -1).replace(/\//g, '').toUpperCase();
        const uri = `https://svgs.scryfall.io/card-symbols/${code}.svg`;
        return <SvgUri key={`${sym}-${i}`} uri={uri} width={size} height={size} />;
      })}
    </View>
  );
}

function getCardType(typeLine: string): string {
  if (typeLine.includes('Planeswalker')) return 'Planeswalker';
  if (typeLine.includes('Creature')) return 'Creature';
  if (typeLine.includes('Instant')) return 'Instant';
  if (typeLine.includes('Sorcery')) return 'Sorcery';
  if (typeLine.includes('Enchantment')) return 'Enchantment';
  if (typeLine.includes('Artifact')) return 'Artifact';
  if (typeLine.includes('Land')) return 'Land';
  return 'Other';
}

function buildSections(cards: Card[], format?: string | null): Section[] {
  const isCommander = format === 'commander' || format === 'edh';
  const commanders = isCommander ? cards.filter((c) => !c.pivot.is_sideboard && c.pivot.is_commander) : [];
  const mainboard = cards.filter((c) => !c.pivot.is_sideboard && !c.pivot.is_commander);
  const sideboard = cards.filter((c) => !!c.pivot.is_sideboard);

  const grouped: Record<string, Card[]> = {};
  for (const card of mainboard) {
    const type = getCardType(card.type_line);
    if (!grouped[type]) grouped[type] = [];
    grouped[type].push(card);
  }

  const sections: Section[] = [];

  if (commanders.length > 0) {
    sections.push({
      title: `Commander (${commanders.reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: commanders.sort((a, b) => a.name.localeCompare(b.name)),
    });
  }

  TYPE_ORDER.filter((t) => grouped[t]?.length > 0).forEach((t) => {
    sections.push({
      title: `${t === 'Sorcery' ? 'Sorceries' : `${t}s`} (${grouped[t].reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: grouped[t].sort((a, b) => a.name.localeCompare(b.name)),
    });
  });

  if (sideboard.length > 0) {
    sections.push({
      title: `Sideboard (${sideboard.reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: sideboard.sort((a, b) => a.name.localeCompare(b.name)),
    });
  }

  return sections;
}

export default function DeckViewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { token } = useAuth();
  const router = useRouter();

  const [deck, setDeck] = useState<Deck | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedCard, setSelectedCard] = useState<Card | null>(null);
  const [removing, setRemoving] = useState(false);
  const [deletingDeck, setDeletingDeck] = useState(false);

  const fetchDeck = useCallback(async () => {
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      setDeck(await res.json());
    } catch {
      // network error
    }
  }, [id, token]);

  useEffect(() => {
    fetchDeck().finally(() => setLoading(false));
  }, [fetchDeck]);

  async function handleRemoveCard(card: Card) {
    setRemoving(true);
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${id}/cards/${card.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error('Failed to remove card');
      setSelectedCard(null);
      await fetchDeck();
    } catch {
      Alert.alert('Error', 'Failed to remove card from deck.');
    } finally {
      setRemoving(false);
    }
  }

  function confirmRemove(card: Card) {
    Alert.alert('Remove Card', `Remove ${card.name} from the deck?`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: () => handleRemoveCard(card) },
    ]);
  }

  async function handleDeleteDeck() {
    if (!deck) return;

    setDeletingDeck(true);
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${deck.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        throw new Error('Failed to delete deck');
      }

      router.replace('/decks');
    } catch {
      Alert.alert('Error', 'Failed to delete deck.');
    } finally {
      setDeletingDeck(false);
    }
  }

  function confirmDeleteDeck() {
    if (!deck || deletingDeck) return;

    Alert.alert('Delete Deck', `Delete ${deck.name}? This cannot be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Delete', style: 'destructive', onPress: handleDeleteDeck },
    ]);
  }

  const totalCards = deck?.cards.reduce((sum, c) => sum + c.pivot.quantity, 0) ?? 0;
  const totalPrice = deck?.cards.reduce((sum, c) => sum + (c.price_usd ?? 0) * c.pivot.quantity, 0) ?? 0;
  const sections = deck ? buildSections(deck.cards, deck.format) : [];

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  if (!deck) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>Deck not found.</Text>
        <TouchableOpacity onPress={() => router.back()} style={styles.backButton}>
          <Text style={styles.backButtonText}>Go Back</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
          <Ionicons name="chevron-back" size={24} color="#fff" />
        </TouchableOpacity>
        <View style={styles.headerInfo}>
          <Text style={styles.deckName} numberOfLines={1}>
            {deck.name}
          </Text>
          <View style={styles.headerMetaRow}>
            <Text style={styles.deckMeta}>
              {FORMATS[deck.format ?? ''] ?? (deck.format ?? 'Casual')} • {totalCards} cards
            </Text>
            {totalPrice > 0 && (
              <Text style={styles.deckPrice}>${totalPrice.toFixed(2)}</Text>
            )}
            {deck.color_identity && deck.color_identity.length > 0 && (
              <View style={styles.colorPips}>
                {deck.color_identity.map((c) => {
                  const def = COLOR_DEFS[c];
                  if (!def) return null;
                  return (
                    <View key={c} style={[styles.colorPip, { backgroundColor: def.bg }]}>
                      <Text style={[styles.colorPipText, { color: def.text }]}>{c}</Text>
                    </View>
                  );
                })}
              </View>
            )}
          </View>
        </View>
        <TouchableOpacity
          onPress={confirmDeleteDeck}
          style={[styles.deleteDeckBtn, deletingDeck && styles.buttonDisabled]}
          disabled={deletingDeck}
        >
          {deletingDeck ? (
            <ActivityIndicator color="#ffb4b4" size="small" />
          ) : (
            <Ionicons name="trash-outline" size={20} color="#ff8c8c" />
          )}
        </TouchableOpacity>
      </View>

      <TouchableOpacity
        style={styles.assistantBtn}
        onPress={() => router.push({ pathname: '/assistant', params: { deckId: String(deck.id), deckName: deck.name } })}
      >
        <Ionicons name="sparkles" size={18} color="#fff" />
        <Text style={styles.assistantBtnText}>Ask AI to improve this deck</Text>
      </TouchableOpacity>

      {sections.length === 0 ? (
        <View style={styles.emptyState}>
          <Ionicons name="layers-outline" size={64} color="#433647" />
          <Text style={styles.emptyTitle}>No cards yet</Text>
          <Text style={styles.emptySubtitle}>
            Add cards from your collection or use the AI Assistant to populate this deck.
          </Text>
        </View>
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={(item) => item.id.toString()}
          contentContainerStyle={styles.listContent}
          renderSectionHeader={({ section }) => (
            <View style={styles.sectionHeader}>
              <Text style={styles.sectionTitle}>{section.title}</Text>
            </View>
          )}
          renderItem={({ item }) => (
            <TouchableOpacity style={styles.cardRow} onPress={() => setSelectedCard(item)}>
              <View style={styles.cardThumbContainer}>
                {item.image_uri ? (
                  <Image source={{ uri: item.image_uri }} style={styles.cardThumb} resizeMode="cover" />
                ) : (
                  <View style={styles.cardThumbPlaceholder}>
                    <Ionicons name="image-outline" size={18} color="#433647" />
                  </View>
                )}
                <View style={styles.qtyOverlay}>
                  <Text style={styles.qtyOverlayText}>{item.pivot.quantity}</Text>
                </View>
              </View>
              <View style={styles.cardInfo}>
                <Text style={styles.cardName}>{item.name}</Text>
                <Text style={styles.cardType} numberOfLines={1}>
                  {item.type_line}
                </Text>
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                {item.mana_cost ? (
                  <ManaCost cost={item.mana_cost} size={16} />
                ) : null}
                {item.price_usd != null && (
                  <Text style={styles.cardPrice}>${(item.price_usd * item.pivot.quantity).toFixed(2)}</Text>
                )}
              </View>
              <Ionicons name="chevron-forward" size={16} color="#555" style={styles.rowChevron} />
            </TouchableOpacity>
          )}
          stickySectionHeadersEnabled={false}
        />
      )}

      {/* Card Detail Modal */}
      <Modal
        animationType="slide"
        transparent
        visible={!!selectedCard}
        onRequestClose={() => setSelectedCard(null)}
      >
        {selectedCard && (
          <View style={styles.modalOverlay}>
            <View style={styles.modalContent}>
              <View style={styles.modalHeader}>
                <Text style={styles.modalTitle} numberOfLines={1}>
                  {selectedCard.name}
                </Text>
                <TouchableOpacity style={styles.closeButton} onPress={() => setSelectedCard(null)}>
                  <Ionicons name="close" size={24} color="#fff" />
                </TouchableOpacity>
              </View>

              <ScrollView contentContainerStyle={styles.modalScroll}>
                {selectedCard.image_uri ? (
                  <Image
                    source={{ uri: selectedCard.image_uri }}
                    style={styles.cardImage}
                    resizeMode="contain"
                  />
                ) : (
                  <View style={[styles.cardImage, styles.cardImagePlaceholder]}>
                    <Ionicons name="image-outline" size={48} color="#433647" />
                  </View>
                )}

                <View style={styles.modalInfo}>
                  {selectedCard.mana_cost ? <ManaCost cost={selectedCard.mana_cost} size={20} /> : null}
                  <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                  <Text style={styles.modalSet}>{selectedCard.set_name} • {selectedCard.rarity}</Text>
                  {selectedCard.price_usd != null && (
                    <Text style={styles.modalPrice}>${(selectedCard.price_usd * selectedCard.pivot.quantity).toFixed(2)}</Text>
                  )}
                  <View style={styles.cardDetailRow}>
                    <View style={styles.qtyBadgeLarge}>
                      <Text style={styles.qtyTextLarge}>×{selectedCard.pivot.quantity}</Text>
                    </View>
                    {!!selectedCard.pivot.is_sideboard && (
                      <View style={styles.sideboardBadge}>
                        <Text style={styles.sideboardText}>Sideboard</Text>
                      </View>
                    )}
                  </View>
                </View>
              </ScrollView>

              <View style={styles.modalActions}>
                <TouchableOpacity
                  style={[styles.removeButton, removing && styles.buttonDisabled]}
                  onPress={() => confirmRemove(selectedCard)}
                  disabled={removing}
                >
                  {removing ? (
                    <ActivityIndicator color="#fff" size="small" />
                  ) : (
                    <>
                      <Ionicons name="trash-outline" size={18} color="#fff" style={{ marginRight: 8 }} />
                      <Text style={styles.removeButtonText}>Remove from Deck</Text>
                    </>
                  )}
                </TouchableOpacity>
              </View>
            </View>
          </View>
        )}
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f0f1a' },
  centered: { flex: 1, backgroundColor: '#0f0f1a', alignItems: 'center', justifyContent: 'center' },
  errorText: { color: '#888', fontSize: 16, marginBottom: 16 },
  backButton: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 10,
    paddingHorizontal: 20,
    borderRadius: 8,
  },
  backButtonText: { color: '#fff', fontWeight: '600' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingTop: 56,
    paddingBottom: 16,
    paddingHorizontal: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  backBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 14,
  },
  headerInfo: { flex: 1 },
  deleteDeckBtn: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: 12,
  },
  deckName: { color: '#fff', fontSize: 20, fontWeight: '700' },
  headerMetaRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 2 },
  assistantBtn: {
    marginHorizontal: 16,
    marginTop: 12,
    marginBottom: 4,
    backgroundColor: '#6C3CE1',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  assistantBtnText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '700',
  },
  deckMeta: { color: '#888', fontSize: 13 },
  deckPrice: { color: '#7dcea0', fontSize: 13, fontWeight: '600', marginTop: 2 },
  colorPips: { flexDirection: 'row', gap: 3 },
  colorPip: {
    width: 16,
    height: 16,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  colorPipText: { fontSize: 9, fontWeight: '800' },
  listContent: { paddingHorizontal: 16, paddingBottom: 32, paddingTop: 8 },
  sectionHeader: {
    paddingTop: 16,
    paddingBottom: 6,
  },
  sectionTitle: {
    color: '#6C3CE1',
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
  },
  cardRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 14,
    marginBottom: 6,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  cardThumbContainer: {
    width: 44,
    height: 60,
    borderRadius: 4,
    marginRight: 12,
    overflow: 'hidden',
    position: 'relative',
  },
  cardThumb: {
    width: '100%',
    height: '100%',
  },
  cardThumbPlaceholder: {
    width: '100%',
    height: '100%',
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 4,
  },
  qtyOverlay: {
    position: 'absolute',
    bottom: 2,
    right: 2,
    backgroundColor: 'rgba(108, 60, 225, 0.92)',
    borderRadius: 4,
    minWidth: 18,
    paddingHorizontal: 3,
    paddingVertical: 1,
    alignItems: 'center',
  },
  qtyOverlayText: { color: '#fff', fontSize: 11, fontWeight: '700' },
  cardInfo: { flex: 1 },
  cardName: { color: '#fff', fontSize: 15, fontWeight: '600' },
  cardType: { color: '#888', fontSize: 12, marginTop: 2 },
  cardPrice: { color: '#7dcea0', fontSize: 12, marginLeft: 6 },
  rowChevron: { marginLeft: 4 },
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
  },
  emptyTitle: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 16, marginBottom: 8 },
  emptySubtitle: { color: '#888', fontSize: 14, textAlign: 'center', lineHeight: 20 },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.85)', justifyContent: 'flex-end' },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    paddingTop: 16,
    paddingBottom: Platform.OS === 'ios' ? 40 : 20,
    height: '85%',
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingBottom: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  modalTitle: { color: '#fff', fontSize: 18, fontWeight: '700', flex: 1, marginRight: 10 },
  closeButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(255,255,255,0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalScroll: {
    padding: 20,
    alignItems: 'center',
  },
  cardImage: {
    width: SCREEN_WIDTH * 0.7,
    height: (SCREEN_WIDTH * 0.7) * 1.4,
    borderRadius: 12,
    marginBottom: 20,
  },
  cardImagePlaceholder: {
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalInfo: {
    alignItems: 'center',
    marginBottom: 8,
    gap: 6,
  },
  modalType: { color: '#aaa', fontSize: 15, textAlign: 'center' },
  modalSet: { color: '#666', fontSize: 13, textAlign: 'center' },
  modalPrice: { color: '#7dcea0', fontSize: 15, fontWeight: '600' },
  modalActions: {
    paddingHorizontal: 20,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#2a2a3e',
  },
  cardDetailRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  qtyBadgeLarge: {
    backgroundColor: '#6C3CE1',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 5,
  },
  qtyTextLarge: { color: '#fff', fontSize: 14, fontWeight: '700' },
  sideboardBadge: {
    backgroundColor: '#2a2a3e',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 5,
    borderWidth: 1,
    borderColor: '#3a3a5e',
  },
  sideboardText: { color: '#888', fontSize: 13, fontWeight: '600' },
  removeButton: {
    backgroundColor: '#c0392b',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'row',
  },
  removeButtonText: { color: '#fff', fontSize: 15, fontWeight: '700' },
  buttonDisabled: { opacity: 0.55 },
});
