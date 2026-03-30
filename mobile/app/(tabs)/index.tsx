import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Dimensions,
  FlatList,
  Image,
  Modal,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';
const { width: SCREEN_WIDTH } = Dimensions.get('window');

type CollectionCard = {
  id: number;
  scryfall_id: string;
  name: string;
  set_name: string;
  set_code: string;
  rarity: string;
  type_line: string;
  image_uri: string | null;
  pivot: {
    quantity: number;
    foil: boolean;
  };
};

type Deck = {
  id: number;
  name: string;
  format: string | null;
  cards_count: number;
};

export default function CollectionScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const [cards, setCards] = useState<CollectionCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Card detail modal
  const [selectedCard, setSelectedCard] = useState<CollectionCard | null>(null);
  const [updating, setUpdating] = useState(false);

  // Deck picker
  const [decks, setDecks] = useState<Deck[]>([]);

  const [loadingDecks, setLoadingDecks] = useState(false);
  const [addingToDeck, setAddingToDeck] = useState(false);
  const [modalView, setModalView] = useState<'card' | 'deck-picker'>('card');

  const fetchCollection = useCallback(async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!response.ok) return;
      const data = await response.json();
      setCards(data);
    } catch {
      // network error — leave previous state intact
    }
  }, [token]);

  useEffect(() => {
    fetchCollection().finally(() => setLoading(false));
  }, [fetchCollection]);

  async function handleRefresh() {
    setRefreshing(true);
    await fetchCollection();
    setRefreshing(false);
  }

  async function handleQuantityChange(delta: number) {
    if (!selectedCard || updating) return;

    const newQuantity = selectedCard.pivot.quantity + delta;

    // Optimistic update
    const updatedCard = { ...selectedCard, pivot: { ...selectedCard.pivot, quantity: newQuantity } };
    setSelectedCard(updatedCard);
    setCards(prev =>
      newQuantity <= 0
        ? prev.filter(c => !(c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil))
        : prev.map(c =>
            c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil
              ? updatedCard
              : c
          )
    );

    setUpdating(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection/${selectedCard.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity: newQuantity, foil: selectedCard.pivot.foil }),
      });

      if (!response.ok) throw new Error('Update failed');

      if (newQuantity <= 0) {
        setSelectedCard(null);
      }
    } catch {
      // Revert optimistic update
      setSelectedCard(selectedCard);
      setCards(prev =>
        prev.map(c =>
          c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil ? selectedCard : c
        )
      );
      Alert.alert('Error', 'Could not update quantity.');
    } finally {
      setUpdating(false);
    }
  }

  const fetchDecks = async () => {
    setLoadingDecks(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (response.ok) {
        const data = await response.json();
        setDecks(data);
      }
    } catch {
      // ignore
    } finally {
      setLoadingDecks(false);
    }
  };

  const handleOpenDeckPicker = () => {
    fetchDecks();
    setModalView('deck-picker');
  };

  const addToDeck = async (deckId: number) => {
    if (!selectedCard) return;

    setAddingToDeck(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks/${deckId}/cards`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ scryfall_id: selectedCard.scryfall_id, quantity: 1 }),
      });

      if (response.ok) {
        setModalView('card');
        setSelectedCard(null);
        Alert.alert('Success', `${selectedCard.name} added to deck!`);
      } else {
        throw new Error('Failed to add to deck');
      }
    } catch {
      Alert.alert('Error', 'Could not add card to deck.');
    } finally {
      setAddingToDeck(false);
    }
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.header}>My Collection</Text>
      <FlatList
        data={cards}
        keyExtractor={(item) => `${item.scryfall_id}-${item.pivot.foil}`}
        numColumns={2}
        contentContainerStyle={cards.length === 0 ? styles.emptyContent : styles.listContent}
        columnWrapperStyle={styles.row}
        refreshing={refreshing}
        onRefresh={handleRefresh}
        ListEmptyComponent={
          <View style={styles.emptyState}>
            <Text style={styles.emptyTitle}>No cards yet</Text>
            <Text style={styles.emptySubtitle}>Scan your first card to start your collection.</Text>
            <TouchableOpacity style={styles.scanCta} onPress={() => router.push('/(tabs)/scan')}>
              <Text style={styles.scanCtaText}>Scan a Card</Text>
            </TouchableOpacity>
          </View>
        }
        renderItem={({ item }) => (
          <TouchableOpacity onPress={() => { setModalView('card'); setSelectedCard(item); }} activeOpacity={0.8}>
            <CardItem card={item} />
          </TouchableOpacity>
        )}
      />

      {/* Card Detail / Deck Picker — single modal, view toggled by modalView */}
      <Modal
        visible={!!selectedCard}
        animationType="slide"
        transparent={true}
        onRequestClose={() => {
          if (modalView === 'deck-picker') {
            setModalView('card');
          } else {
            setSelectedCard(null);
          }
        }}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            {selectedCard && modalView === 'card' && (
              <>
                <View style={styles.modalHeader}>
                  <Text style={styles.modalTitle} numberOfLines={1}>{selectedCard.name}</Text>
                  <TouchableOpacity
                    style={styles.closeButton}
                    onPress={() => setSelectedCard(null)}
                  >
                    <Ionicons name="close" size={24} color="#fff" />
                  </TouchableOpacity>
                </View>

                <ScrollView contentContainerStyle={styles.modalScroll}>
                  {selectedCard.image_uri ? (
                    <Image
                      source={{ uri: selectedCard.image_uri }}
                      style={styles.modalImage}
                      resizeMode="contain"
                    />
                  ) : (
                    <View style={[styles.modalImage, styles.modalImagePlaceholder]}>
                      <Ionicons name="image-outline" size={48} color="#444" />
                    </View>
                  )}

                  <View style={styles.modalInfo}>
                    <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                    <Text style={styles.modalSet}>
                      {selectedCard.set_name} • {selectedCard.rarity}
                    </Text>
                    {selectedCard.pivot.foil ? (
                      <View style={styles.foilBadgeModal}>
                        <Text style={styles.foilBadgeText}>Foil</Text>
                      </View>
                    ) : null}
                  </View>

                  {/* Quantity Controls */}
                  <View style={styles.quantitySection}>
                    <Text style={styles.quantityLabel}>Owned</Text>
                    <View style={styles.quantityControls}>
                      <TouchableOpacity
                        style={[styles.qtyButton, updating && styles.qtyButtonDisabled]}
                        onPress={() => handleQuantityChange(-1)}
                        disabled={updating}
                      >
                        <Ionicons name="remove" size={20} color="#fff" />
                      </TouchableOpacity>
                      <Text style={styles.quantityValue}>{selectedCard.pivot.quantity}</Text>
                      <TouchableOpacity
                        style={[styles.qtyButton, styles.qtyButtonAdd, updating && styles.qtyButtonDisabled]}
                        onPress={() => handleQuantityChange(1)}
                        disabled={updating}
                      >
                        <Ionicons name="add" size={20} color="#fff" />
                      </TouchableOpacity>
                    </View>
                    {updating && <ActivityIndicator size="small" color="#6C3CE1" style={{ marginTop: 4 }} />}
                  </View>

                  {/* Add to Deck */}
                  <TouchableOpacity
                    style={styles.deckButton}
                    onPress={handleOpenDeckPicker}
                    disabled={updating || addingToDeck}
                  >
                    <Ionicons name="albums" size={20} color="#fff" />
                    <Text style={styles.deckButtonText}>Add to Deck</Text>
                  </TouchableOpacity>
                </ScrollView>
              </>
            )}

            {selectedCard && modalView === 'deck-picker' && (
              <>
                <View style={[styles.modalHeader, { paddingHorizontal: 20 }]}>
                  <TouchableOpacity style={styles.closeButton} onPress={() => setModalView('card')}>
                    <Ionicons name="arrow-back" size={22} color="#fff" />
                  </TouchableOpacity>
                  <Text style={[styles.modalTitle, { marginLeft: 8 }]}>Choose a Deck</Text>
                  <TouchableOpacity style={styles.closeButton} onPress={() => setSelectedCard(null)}>
                    <Ionicons name="close" size={24} color="#fff" />
                  </TouchableOpacity>
                </View>

                {loadingDecks ? (
                  <ActivityIndicator color="#6C3CE1" style={{ marginVertical: 40 }} />
                ) : decks.length === 0 ? (
                  <View style={styles.emptyPicker}>
                    <Text style={styles.emptyPickerText}>You don't have any decks yet.</Text>
                    <TouchableOpacity
                      style={styles.createDeckLink}
                      onPress={() => {
                        setSelectedCard(null);
                        router.push('/(tabs)/decks');
                      }}
                    >
                      <Text style={styles.createDeckLinkText}>Create your first deck</Text>
                    </TouchableOpacity>
                  </View>
                ) : (
                  <FlatList
                    data={decks}
                    keyExtractor={(item) => item.id.toString()}
                    renderItem={({ item }) => (
                      <TouchableOpacity
                        style={styles.deckSelectItem}
                        onPress={() => addToDeck(item.id)}
                        disabled={addingToDeck}
                      >
                        <View>
                          <Text style={styles.deckSelectName}>{item.name}</Text>
                          <Text style={styles.deckSelectMeta}>
                            {item.format ? item.format.toUpperCase() : 'CASUAL'} • {item.cards_count} cards
                          </Text>
                        </View>
                        {addingToDeck
                          ? <ActivityIndicator size="small" color="#6C3CE1" />
                          : <Ionicons name="add-circle" size={24} color="#6C3CE1" />
                        }
                      </TouchableOpacity>
                    )}
                    contentContainerStyle={{ paddingHorizontal: 20 }}
                    style={{ maxHeight: 400 }}
                  />
                )}
              </>
            )}
          </View>
        </View>
      </Modal>
    </View>
  );
}

function CardItem({ card }: { card: CollectionCard }) {
  return (
    <View style={styles.cardItem}>
      {card.image_uri ? (
        <Image source={{ uri: card.image_uri }} style={styles.cardImage} resizeMode="cover" />
      ) : (
        <View style={styles.cardImagePlaceholder}>
          <Text style={styles.cardImagePlaceholderText}>{card.name}</Text>
        </View>
      )}
      <View style={styles.cardInfo}>
        <Text style={styles.cardName} numberOfLines={1}>{card.name}</Text>
        <Text style={styles.cardMeta} numberOfLines={1}>{card.set_name}</Text>
        <View style={styles.cardBadges}>
          <View style={styles.quantityBadge}>
            <Text style={styles.quantityText}>×{card.pivot.quantity}</Text>
          </View>
          {card.pivot.foil ? (
            <View style={styles.foilBadge}>
              <Text style={styles.foilText}>Foil</Text>
            </View>
          ) : null}
        </View>
      </View>
    </View>
  );
}

const CARD_WIDTH = 160;
const CARD_HEIGHT = 224;

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  centered: {
    flex: 1,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
  },
  header: {
    color: '#fff',
    fontSize: 22,
    fontWeight: '700',
    paddingHorizontal: 16,
    paddingTop: 56,
    paddingBottom: 12,
  },
  listContent: {
    paddingHorizontal: 12,
    paddingBottom: 24,
  },
  emptyContent: {
    flex: 1,
  },
  row: {
    justifyContent: 'space-between',
    marginBottom: 16,
  },

  // Card item
  cardItem: {
    width: CARD_WIDTH,
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    overflow: 'hidden',
  },
  cardImage: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
  },
  cardImagePlaceholder: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 8,
  },
  cardImagePlaceholderText: {
    color: '#888',
    fontSize: 12,
    textAlign: 'center',
  },
  cardInfo: {
    padding: 8,
  },
  cardName: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
    marginBottom: 2,
  },
  cardMeta: {
    color: '#888',
    fontSize: 11,
    marginBottom: 6,
  },
  cardBadges: {
    flexDirection: 'row',
    gap: 6,
  },
  quantityBadge: {
    backgroundColor: '#2a2a3e',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  quantityText: {
    color: '#ccc',
    fontSize: 11,
    fontWeight: '600',
  },
  foilBadge: {
    backgroundColor: '#3d2a6e',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  foilText: {
    color: '#a78bfa',
    fontSize: 11,
    fontWeight: '600',
  },

  // Empty state
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 40,
  },
  emptyTitle: {
    color: '#fff',
    fontSize: 20,
    fontWeight: '700',
    marginBottom: 8,
  },
  emptySubtitle: {
    color: '#888',
    fontSize: 14,
    textAlign: 'center',
    marginBottom: 24,
  },
  scanCta: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 28,
    borderRadius: 8,
  },
  scanCtaText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },

  // Modal
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.85)',
    justifyContent: 'flex-end',
  },
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
  modalTitle: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '700',
    flex: 1,
    marginRight: 10,
  },
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
  modalImage: {
    width: SCREEN_WIDTH * 0.7,
    height: (SCREEN_WIDTH * 0.7) * 1.4,
    borderRadius: 12,
    marginBottom: 20,
  },
  modalImagePlaceholder: {
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalInfo: {
    alignItems: 'center',
    marginBottom: 24,
  },
  modalType: {
    color: '#aaa',
    fontSize: 15,
    textAlign: 'center',
    marginBottom: 6,
  },
  modalSet: {
    color: '#666',
    fontSize: 13,
    textAlign: 'center',
    marginBottom: 8,
  },
  foilBadgeModal: {
    backgroundColor: '#3d2a6e',
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 6,
    marginTop: 4,
  },
  foilBadgeText: {
    color: '#a78bfa',
    fontSize: 12,
    fontWeight: '600',
  },

  // Quantity controls
  quantitySection: {
    alignItems: 'center',
    marginBottom: 20,
    width: '100%',
  },
  quantityLabel: {
    color: '#888',
    fontSize: 13,
    fontWeight: '500',
    marginBottom: 10,
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  quantityControls: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 20,
  },
  qtyButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyButtonAdd: {
    backgroundColor: '#6C3CE1',
  },
  qtyButtonDisabled: {
    opacity: 0.5,
  },
  quantityValue: {
    color: '#fff',
    fontSize: 28,
    fontWeight: '700',
    minWidth: 40,
    textAlign: 'center',
  },

  // Deck button
  deckButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#2a2a3e',
    paddingVertical: 14,
    borderRadius: 12,
    width: '100%',
    gap: 8,
  },
  deckButtonText: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
  },

  // Deck Picker
  pickerOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.8)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20,
  },
  pickerContent: {
    backgroundColor: '#1a1a2e',
    borderRadius: 16,
    width: '100%',
    padding: 20,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  pickerHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 20,
  },
  pickerTitle: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '700',
  },
  deckSelectItem: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  deckSelectName: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
    marginBottom: 2,
  },
  deckSelectMeta: {
    color: '#888',
    fontSize: 12,
  },
  emptyPicker: {
    paddingVertical: 20,
    alignItems: 'center',
  },
  emptyPickerText: {
    color: '#888',
    marginBottom: 12,
  },
  createDeckLink: {
    padding: 8,
  },
  createDeckLinkText: {
    color: '#6C3CE1',
    fontWeight: '600',
  },
});
