import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Image,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { useRouter } from 'expo-router';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

type CollectionCard = {
  id: number;
  scryfall_id: string;
  name: string;
  set_name: string;
  rarity: string;
  type_line: string;
  image_uri: string | null;
  pivot: {
    quantity: number;
    foil: boolean;
  };
};

export default function CollectionScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const [cards, setCards] = useState<CollectionCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

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
        renderItem={({ item }) => <CardItem card={item} />}
      />
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
const CARD_HEIGHT = 224; // ~1:1.4 MTG ratio

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
});
